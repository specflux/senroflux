<?php
/**
 * Plugin Name: SenroFlux E2E Fake Provider
 * Description: E2E/CI-ONLY. A scripted, deterministic AI Client provider
 *              (no API key, no network) plus a synthetic "senroflux-e2e"
 *              governed namespace with a handful of fixture abilities, so
 *              the Playwright suite (0.3 S10/S12/S22) can drive real
 *              multi-turn runs over real HTTP against a real WordPress
 *              install without ever calling a live model. Modelled directly
 *              on dev/smoke/senroflux-spike-fixture.php and
 *              dev/smoke/agsafe-smoke-fixture.php (same shapes, same
 *              filters) — NOT wp_register_ability()'d via SenroFlux's own
 *              Pack classes, so a run started from the React screen picks
 *              these calls up through the union allow-list
 *              RunsScreen::registerAdminConsumer() already builds from every
 *              registered pack, appended here rather than replacing it.
 *
 * Excluded from the shipped zip: `tests/` is already fully excluded by
 * .distignore. Mounted ONLY in this worktree's own wp-env
 * (.wp-env.json here), never in the shared instance on :8888.
 *
 * Script protocol: `wp option update senroflux_e2e_script '<json>'` (a JSON
 * list of steps: {"type":"text","text":"..."} or
 * {"type":"calls","calls":[{"name":"...","args":{...}}]}), consumed ONE
 * step per generateTextResult() call (S9: a tick never makes more than one
 * model turn). Every consumed step is also appended to
 * `senroflux_e2e_calls` so a test can assert how many turns a scenario
 * actually took. Never never ship.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;

// ---------------------------------------------------------------------------
// Scripted AI provider.
// ---------------------------------------------------------------------------

class SenroFlux_E2E_Provider_Availability implements ProviderAvailabilityInterface {
	public function isConfigured(): bool {
		return true;
	}
}

class SenroFlux_E2E_Model_Metadata_Directory implements ModelMetadataDirectoryInterface {
	public function listModelMetadata(): array {
		return array(
			new ModelMetadata(
				'senroflux-e2e-model',
				'SenroFlux E2E Fake Model',
				array(
					CapabilityEnum::textGeneration(),
					CapabilityEnum::chatHistory(),
				),
				array(
					new SupportedOption( OptionEnum::functionDeclarations() ),
					new SupportedOption( OptionEnum::maxTokens() ),
					new SupportedOption( OptionEnum::systemInstruction() ),
					new SupportedOption( OptionEnum::inputModalities() ),
					new SupportedOption( OptionEnum::outputModalities() ),
				)
			),
		);
	}

	public function hasModelMetadata( string $model_id ): bool {
		return 'senroflux-e2e-model' === $model_id;
	}

	public function getModelMetadata( string $model_id ): ModelMetadata {
		if ( ! $this->hasModelMetadata( $model_id ) ) {
			throw new InvalidArgumentException( 'Unknown model: ' . $model_id );
		}
		return $this->listModelMetadata()[0];
	}
}

class SenroFlux_E2E_Model implements ModelInterface, TextGenerationModelInterface {

	private ModelConfig $config;

	private static ?ModelMetadata $metadata = null;

	private static ?ProviderMetadata $provider_metadata = null;

	public function __construct( ProviderMetadata $provider_metadata, ModelMetadata $metadata ) {
		self::$provider_metadata = $provider_metadata;
		self::$metadata          = $metadata;
		$this->config            = new ModelConfig();
	}

	public function metadata(): ModelMetadata {
		return self::$metadata;
	}

	public function providerMetadata(): ProviderMetadata {
		return self::$provider_metadata;
	}

	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	public function getConfig(): ModelConfig {
		return $this->config;
	}

	public function generateTextResult( array $prompt ): GenerativeAiResult {
		// The queue is stored as a JSON STRING end-to-end (a lesson already
		// recorded in the spike fixture): re-storing the decoded array would
		// cast it to "Array" on the next read and silently drain the script.
		$script = json_decode( (string) get_option( 'senroflux_e2e_script', '[]' ), true );
		if ( ! is_array( $script ) ) {
			$script = array();
		}
		$step = array_shift( $script );
		update_option( 'senroflux_e2e_script', (string) wp_json_encode( $script ), false );

		$consumed   = (array) get_option( 'senroflux_e2e_calls', array() );
		$consumed[] = $step ?? array( 'type' => 'empty' );
		update_option( 'senroflux_e2e_calls', $consumed, false );

		if ( null === $step || 'text' === ( $step['type'] ?? '' ) ) {
			$parts = array( new MessagePart( (string) ( $step['text'] ?? 'No scripted response left.' ) ) );
		} else {
			$parts = array();
			foreach ( (array) ( $step['calls'] ?? array() ) as $i => $call ) {
				$parts[] = new MessagePart(
					new FunctionCall(
						'call_' . count( $consumed ) . '_' . $i,
						(string) ( $call['name'] ?? '' ),
						(array) ( $call['args'] ?? array() )
					)
				);
			}
		}

		return new GenerativeAiResult(
			'res_' . uniqid(),
			array( new Candidate( new ModelMessage( $parts ), FinishReasonEnum::stop() ) ),
			new TokenUsage( 10, 5, 15 ),
			self::$provider_metadata,
			self::$metadata
		);
	}
}

class SenroFlux_E2E_Provider extends AbstractProvider {

	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			'senroflux-e2e',
			'SenroFlux E2E Fake Provider',
			ProviderTypeEnum::cloud(),
			null,
			RequestAuthenticationMethod::apiKey()
		);
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new SenroFlux_E2E_Provider_Availability();
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new SenroFlux_E2E_Model_Metadata_Directory();
	}

	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		return new SenroFlux_E2E_Model( $provider_metadata, $model_metadata );
	}
}

add_action(
	'init',
	static function (): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( 'senroflux-e2e' ) ) {
			$registry->registerProvider( SenroFlux_E2E_Provider::class );
		}
	},
	-20
);

// ---------------------------------------------------------------------------
// Fixture abilities: a synthetic "senroflux-e2e" namespace with three Tier-0
// reads and one Tier-1 write, so a scripted scenario can exercise the real
// ledger/tier-badge/park machinery without depending on any real content
// pack's validators.
// ---------------------------------------------------------------------------

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'senroflux-e2e',
			array(
				'label'       => 'SenroFlux E2E',
				'description' => 'Synthetic abilities for the Playwright suite.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'senroflux-e2e/read-thing',
			array(
				'label'               => 'Read thing',
				'description'         => 'Fixture: reads a synthetic record.',
				'category'            => 'senroflux-e2e',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input = array() ): array {
					return array(
						'ok'   => true,
						'id'   => (string) ( is_array( $input ) ? ( $input['id'] ?? 'thing-1' ) : 'thing-1' ),
						'body' => 'Fixture thing content.',
					);
				},
				'permission_callback' => static fn (): bool => current_user_can( 'read' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'senroflux-e2e/list-things',
			array(
				'label'               => 'List things',
				'description'         => 'Fixture: lists synthetic records.',
				'category'            => 'senroflux-e2e',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn (): array => array(
					'ok'     => true,
					'things' => array( 'thing-1', 'thing-2' ),
				),
				'permission_callback' => static fn (): bool => current_user_can( 'read' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'senroflux-e2e/search-things',
			array(
				'label'               => 'Search things',
				'description'         => 'Fixture: searches synthetic records.',
				'category'            => 'senroflux-e2e',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'query' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn (): array => array(
					'ok'      => true,
					'matches' => array( 'thing-2' ),
				),
				'permission_callback' => static fn (): bool => current_user_can( 'read' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'senroflux-e2e/create-thing',
			array(
				'label'               => 'Create thing',
				'description'         => 'Fixture: creates a synthetic record. Tier 1 (side-effecting) so it exercises the plan fence and the approval park in BOTH gate modes.',
				'category'            => 'senroflux-e2e',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'title' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input = array() ): array {
					$n = (int) get_option( 'senroflux_e2e_things_created', 0 );
					update_option( 'senroflux_e2e_things_created', $n + 1, false );
					return array(
						'ok'    => true,
						'id'    => 'thing-' . ( $n + 3 ),
						'title' => (string) ( is_array( $input ) ? ( $input['title'] ?? '' ) : '' ),
					);
				},
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'show_in_rest' => true,
					'destructive'  => false,
					'idempotent'   => false,
				),
			)
		);
	}
);

// SenroFlux's OWN tier map (used for the tier badge AND the built-in gate,
// in BOTH gate modes — {@see \Specflux\SenroFlux\Tools\VerbTier}).
add_filter(
	'senroflux_verb_map',
	static function ( array $map ): array {
		$map['senroflux-e2e/read-thing']    = 0;
		$map['senroflux-e2e/list-things']   = 0;
		$map['senroflux-e2e/search-things'] = 0;
		$map['senroflux-e2e/create-thing']  = 1;
		return $map;
	}
);

// Agent Safety's OWN gate, only consulted when it is the active gate mode.
add_filter(
	'agent_safety_governed_namespaces',
	static function ( array $namespaces ): array {
		$namespaces[] = 'senroflux-e2e/';
		return $namespaces;
	}
);

add_filter(
	'agent_safety_verb_map',
	static function ( array $map ): array {
		$map['senroflux-e2e/read-thing']    = 0;
		$map['senroflux-e2e/list-things']   = 0;
		$map['senroflux-e2e/search-things'] = 0;
		$map['senroflux-e2e/create-thing']  = 1;
		return $map;
	}
);

add_filter(
	'agent_safety_pack_registry',
	static function ( $registry ) {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return $registry;
		}
		$registry->register(
			new \Specflux\AgentSafety\Packs\Pack(
				name: 'senroflux-e2e-pack',
				allow: array( 'senroflux-e2e/*' ),
				approvalByClass: array(
					'tier1' => true,
					'tier2' => true,
				),
			)
		);
		return $registry;
	}
);

// None of the real content packs register a run capability in this
// isolated wp-env (Posts/Pages/Site all need their own binding machinery
// this fixture deliberately bypasses), so ScreenCapability::current() would
// otherwise fall back to `do_not_allow` for every non-administrator and the
// S12 "a non-admin sees copyable text, no buttons" suggestion-card scenario
// would have no non-admin who can even open the screen. Pin the Runs-screen
// capability to `edit_posts` so an Editor can reach it; `manage_options`
// (site-brief authority, `canManageSiteBrief`) is unaffected and still
// checked separately.
add_filter( 'senroflux_runs_capability', static fn (): string => 'edit_posts' );

// Append the fixture namespace to whatever allow-list
// RunsScreen::registerAdminConsumer() already built for 'senroflux-admin'
// (priority 20, so it runs AFTER the plugin's own filter at the default 10)
// — appended, never replacing, so every real pack's abilities stay reachable
// too.
add_filter(
	'senroflux_http_consumers',
	static function ( array $consumers ): array {
		if ( ! isset( $consumers['senroflux-admin'] ) || ! is_array( $consumers['senroflux-admin'] ) ) {
			$consumers['senroflux-admin'] = array( 'allow' => array() );
		}
		$allow   = is_array( $consumers['senroflux-admin']['allow'] ?? null ) ? $consumers['senroflux-admin']['allow'] : array();
		$allow[] = 'senroflux-e2e/*';
		$consumers['senroflux-admin']['allow'] = array_values( array_unique( $allow ) );
		return $consumers;
	},
	20
);
