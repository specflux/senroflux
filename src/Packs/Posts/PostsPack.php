<?php
/**
 * The posts capability pack (S5).
 *
 * TARGET REPO PATH: src/Packs/Posts/PostsPack.php
 *
 * Modelled on the pages pack: role → ability template map, the S5 verb map +
 * verb predicate + role→verb split, the pack skills, the withheld-role
 * capability requirement (S6), and the S13 binding check the abstract base
 * requires of every pack.
 *
 * ISOLATION RULE (harness contract): this pack feeds the Runner through the
 * base's explicit seams only. It never touches the run loop.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Posts;

use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The posts pack.
 */
final class PostsPack extends Pack {

	public function __construct() {
		parent::__construct(
			array(
				'read'        => 'read-content',
				'create'      => 'create-post',
				'update'      => 'update-post',
				'publish'     => 'publish-post',
				'preview'     => 'get-preview-url',
				'patterns'    => 'list-patterns',
				'search'      => 'media-search',
				'missing-alt' => 'list-missing-alt',
				'upload'      => 'media-upload',
				'generate'    => 'generate-image',
				'alt-text'    => 'generate-alt-text',
				'featured'    => 'set-featured-image',
				'alt'         => 'update-alt',
				'read-media'  => 'read-media',
				'terms'       => 'set-terms',
				'new-term'    => 'create-term',
			)
		);
	}

	/**
	 * Register the pack's pattern vocabulary (the two feature patterns).
	 * Called from the composition root on `init`; `Vocabulary::register()` is
	 * idempotent.
	 *
	 * @return int Number of patterns registered this call.
	 */
	public function registerPatterns(): int {
		return ( new Vocabulary() )->register();
	}

	/**
	 * @return string 'posts'.
	 */
	public function name(): string {
		return 'posts';
	}

	/**
	 * @return string 'edit_posts' (S5: the posts pack's run capability).
	 */
	public function runCapability(): string {
		return 'edit_posts';
	}

	/**
	 * The input-property keys this pack's client sends, per ability template
	 * (S9 shape-compat seam). The six content-registrar templates are shared
	 * with the pages pack (same abilities, same schema); the media templates
	 * are this pack's own (Media registrar, stage 9).
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	protected function inputProperties( string $template ): array {
		return match ( $template ) {
			'read-content'       => array( 'id', 'post_type', 'slug', 'status', 'author', 'parent', 'fields' ),
			'create-post'        => array( 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'update-post'        => array( 'id', 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'publish-post'       => array( 'id', 'post_type', 'title', 'content', 'status', 'slug', 'parent', 'excerpt' ),
			'get-preview-url'    => array( 'id' ),
			'list-patterns'      => array(),
			'media-search'       => array( 'query' ),
			'list-missing-alt'   => array(),
			'media-upload'       => array( 'file_path' ),
			'generate-image'     => array( 'prompt' ),
			'generate-alt-text'  => array( 'attachment_id' ),
			'set-featured-image' => array( 'post_id', 'attachment_id' ),
			'update-alt'         => array( 'attachment_id', 'alt' ),
			'read-media'         => array( 'attachment_id' ),
			'set-terms'          => array( 'post_id', 'taxonomy', 'term_ids' ),
			'create-term'        => array( 'taxonomy', 'name' ),
			default              => array(),
		};
	}

	/**
	 * The target post's CURRENT status, for the update/publish predicate.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function currentStatus( array $input ): string {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) ) {
			return '';
		}
		if ( ! function_exists( 'get_post_status' ) ) {
			return '';
		}

		$status = get_post_status( (int) $input['id'] );

		return is_string( $status ) ? $status : '';
	}

	/**
	 * The S5 verb predicate: ability + input => PACK verb.
	 *
	 * @param string              $ability The concrete ability id the model called.
	 * @param array<string,mixed> $input   Call input.
	 */
	public function verbFor( string $ability, array $input ): string {
		return match ( $this->baseName( $ability ) ) {
			'read-content'       => 'posts/read',
			'list-patterns'      => 'posts/list-patterns',
			'get-preview-url'    => 'posts/preview',
			'media-search'       => 'posts/media-search',
			'list-missing-alt'   => 'posts/list-missing-alt',
			'create-post'        => 'posts/create-draft',
			'update-post'        => 'posts/update-draft',
			'publish-post'       => $this->publishVerb( $input ),
			'media-upload'       => 'posts/media-upload',
			'generate-image'     => 'posts/media-generate',
			// Not in S5's table: generating a SUGGESTION changes nothing on
			// the site (only `update-alt` persists it), so this is treated
			// like `list-patterns`/`media-search` — a Tier-0 read-alike, not
			// the Tier-1 write `generate-image` itself. Documented deviation.
			'generate-alt-text'  => 'posts/generate-alt-text',
			'set-featured-image' => 'posts/set-featured-image',
			'update-alt'         => 'posts/update-alt',
			'read-media'         => 'posts/read-media',
			'set-terms'          => 'posts/set-terms',
			'create-term'        => 'posts/create-term',
			default              => $ability,
		};
	}

	/**
	 * The publish-post predicate (S4/S5): a transition to `publish` is
	 * `posts/publish`, a transition to `future` is `posts/schedule`, and any
	 * other call reaching this ability (editing an already-public target, or
	 * re-asserting its current public status) is `posts/update-live`.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function publishVerb( array $input ): string {
		$desired = $input['status'] ?? null;
		$current = $this->currentStatus( $input );

		if ( is_string( $desired ) && $desired !== $current ) {
			if ( 'publish' === $desired ) {
				return 'posts/publish';
			}
			if ( 'future' === $desired ) {
				return 'posts/schedule';
			}
		}

		return 'posts/update-live';
	}

	/**
	 * The S5 verb => tier table.
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'posts/read'               => 0,
			'posts/list-patterns'      => 0,
			'posts/preview'            => 0,
			'posts/media-search'       => 0,
			'posts/list-missing-alt'   => 0,
			'posts/generate-alt-text'  => 0,
			'posts/read-media'         => 0,
			'posts/create-draft'       => 1,
			'posts/update-draft'       => 1,
			'posts/set-terms'          => 1,
			'posts/create-term'        => 1,
			'posts/media-upload'       => 1,
			'posts/media-generate'     => 1,
			'posts/set-featured-image' => 1,
			'posts/update-alt'         => 1,
			'posts/update-live'        => 2,
			'posts/publish'            => 2,
			'posts/schedule'           => 2,
		);
	}

	/**
	 * The S5 role => pack-verb split. `update` spans ONLY
	 * `posts/update-draft` (Tier 1) — never a Tier-2 verb — which is what
	 * keeps {@see Pack::agentSafetyVerbMap()} from collapsing a draft edit up
	 * to Tier 2 when this pack's map is merged with the pages pack's in
	 * {@see \Specflux\SenroFlux\Packs\PackRegistry::agentSafetyVerbMap()}:
	 * both packs claim the SAME shared ability `senroflux/update-post`, and
	 * only ever at Tier 1, so the merge's `max()` never has a Tier-2 entry to
	 * pick up.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		return array(
			'read'        => array( 'posts/read' ),
			'create'      => array( 'posts/create-draft' ),
			'update'      => array( 'posts/update-draft' ),
			'publish'     => array( 'posts/update-live', 'posts/publish', 'posts/schedule' ),
			'preview'     => array( 'posts/preview' ),
			'patterns'    => array( 'posts/list-patterns' ),
			'search'      => array( 'posts/media-search' ),
			'missing-alt' => array( 'posts/list-missing-alt' ),
			'upload'      => array( 'posts/media-upload' ),
			'generate'    => array( 'posts/media-generate' ),
			'alt-text'    => array( 'posts/generate-alt-text' ),
			'featured'    => array( 'posts/set-featured-image' ),
			'alt'         => array( 'posts/update-alt' ),
			'read-media'  => array( 'posts/read-media' ),
			'terms'       => array( 'posts/set-terms' ),
			'new-term'    => array( 'posts/create-term' ),
		);
	}

	/**
	 * S12 (defect fix): `update-alt`'s output and `read-media`'s input both
	 * carry the attachment id as `attachment_id`, never `id` — the base's
	 * default would silently track/verify nothing for either.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdKey( string $verb ): string {
		return match ( $verb ) {
			'posts/update-alt', 'posts/read-media' => 'attachment_id',
			default => parent::objectIdKey( $verb ),
		};
	}

	/**
	 * S12 (defect fix): an attachment and a post can share the same numeric
	 * id, so the two verbs above qualify it with {@see Media::OBJECT_ID_PREFIX}
	 * before the harness ever sees it — the same prefix
	 * {@see Media::attachmentLookup()} expects, stripped back off by the
	 * composition root's report lookup (Plugin.php). Every other verb here
	 * keeps a bare id (posts never collide with themselves).
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdPrefix( string $verb ): string {
		return match ( $verb ) {
			'posts/update-alt', 'posts/read-media' => Media::OBJECT_ID_PREFIX,
			default => parent::objectIdPrefix( $verb ),
		};
	}

	/**
	 * S6: `media-upload` and `generate-image` require `upload_files` — a
	 * Contributor holds `edit_posts` but not `upload_files` in stock
	 * WordPress, so those two roles (and only those) are withheld for them.
	 *
	 * @return array<string,string>
	 */
	public function roleCapabilities(): array {
		return array(
			'upload'   => 'upload_files',
			'generate' => 'upload_files',
		);
	}

	/**
	 * S6: one line, in the pack's own words, when the image roles are
	 * withheld.
	 *
	 * @param list<string> $withheld The role names withheld from this run's start().
	 */
	public function withheldRoleNotice( array $withheld ): ?string {
		if ( in_array( 'upload', $withheld, true ) || in_array( 'generate', $withheld, true ) ) {
			return __( 'This run cannot add images.', 'senroflux' );
		}

		return null;
	}

	/**
	 * The four pack skills, in render order (source Pack, version '1').
	 * `posts/content-language` is NOT one of them — S5 promotes it to a
	 * harness skill (see {@see \Specflux\SenroFlux\Skills\SkillSet::harnessSkills()}),
	 * so every run carries it, pack or no pack.
	 *
	 * @return list<Skill>
	 */
	public function skills(): array {
		$vocabulary = new Vocabulary();

		return array(
			new Skill(
				'posts/prose-rules',
				'Prose rules',
				$this->proseRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'posts/copy-rules',
				'Copy rules',
				$this->copyRulesBody( $vocabulary->all() ),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'posts/media-rules',
				'Media rules',
				$this->mediaRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
		);
	}

	/**
	 * The `posts/prose-rules` body: the shape constraints the model needs
	 * (mirrors the pages pack's layout-rules — plain English plus the shape
	 * lines the Validator's structural identity restates).
	 */
	private function proseRulesBody(): string {
		return implode(
			"\n",
			array(
				'Write posts as prose: paragraph, heading, list, quote, image and code blocks may repeat as many times as the post needs — there is no fixed count.',
				'Use at most one closing call to action, placed at the end. Use at most ' . Vocabulary::RULES_MAX_PULL_QUOTE . ' pull quotes, and only for a line that already appears in the body.',
				'Never write a block whose name starts with senroflux/. A closing call to action is a core/group with `{"metadata":{"name":"senroflux/closing-cta"},"align":"full"}` containing a heading, a paragraph and one button. A pull quote is a core/pullquote with `{"metadata":{"name":"senroflux/pull-quote"}}`.',
				'Every core/image MUST carry non-empty, descriptive alt text in its attributes; an image with no alt text is refused.',
				'Write each block comment with compact JSON (no spaces after : or ,). Close everything you open. Markup that does not survive a parse-and-reserialise round trip is refused whole as invalid_markup.',
				'When you propose a plan, spell each step\'s verbs exactly as one of: posts/read, posts/list-patterns, posts/preview, posts/media-search, posts/list-missing-alt, posts/create-draft, posts/update-draft, posts/set-terms, posts/create-term, posts/media-upload, posts/media-generate, posts/generate-alt-text, posts/set-featured-image, posts/update-alt, posts/read-media, posts/update-live, posts/publish, posts/schedule. Any other word is refused as unknown_verb.',
			)
		);
	}

	/**
	 * The `posts/copy-rules` body — RENDERED from the vocabulary's
	 * `constraints.stated` lines, exactly as the pages pack's copy-rules is
	 * (single-source test).
	 *
	 * @param list<array<string,mixed>> $vocabulary {@see Vocabulary::all()}.
	 */
	public function copyRulesBody( array $vocabulary ): string {
		$lines = array();
		foreach ( $vocabulary as $pattern ) {
			$stated = $pattern['constraints']['stated'] ?? array();
			foreach ( $stated as $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * The `posts/media-rules` body (S5, extended by the defect fix): search
	 * before generating, alt text is mandatory, generation costs real money,
	 * and — since nothing else re-reads an attachment for you — re-read it
	 * with `read-media` after changing it.
	 */
	private function mediaRulesBody(): string {
		return implode(
			"\n",
			array(
				'Before generating a new image, search the existing media library; only generate one when nothing suitable already exists.',
				'Every image needs non-empty, descriptive alt text — write it yourself with generate-alt-text or your own words, then save it with update-alt.',
				'Generating an image costs real money and a limited run budget; do not generate more than the goal actually needs.',
				'After update-alt, media-upload or generate-image, call read-media on that attachment id to confirm the change saved — nothing else re-reads it for you.',
			)
		);
	}

	/**
	 * The Agent Safety pack descriptor (allow-list + Tier-2 approval-gate).
	 * See {@see \Specflux\SenroFlux\Packs\Pages\PagesPack::agentSafetyPack()}
	 * for why `allow` is the RESOLVED ABILITY LIST, not the `posts/*` verb
	 * list.
	 *
	 * @return object|null null when the Agent Safety pack class is absent.
	 */
	public function agentSafetyPack(): ?object {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return null;
		}

		return new \Specflux\AgentSafety\Packs\Pack(
			name: 'posts',
			allow: $this->allowList(),
			approvalByClass: array( 'tier2' => true ),
		);
	}

	/**
	 * S13 real binding check — identical reasoning to the pages pack's (see
	 * its docblock); duplicated rather than shared because the two packs'
	 * `agentSafetyPack()` differ (different `name`, different allow-list) and
	 * this is the one piece of Pack machinery with no shared base to hold it.
	 *
	 * @return WP_Error|null null when bound, else pack_unbound.
	 */
	protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
		if ( ! function_exists( 'agent_safety' ) || null === agent_safety() ) {
			return $this->packUnboundError();
		}

		if ( ! class_exists( \Specflux\AgentSafety\Plugin\Support\PackResolver::class )
			|| ! class_exists( \Specflux\AgentSafety\Packs\Pack::class )
			|| ! class_exists( \Specflux\AgentSafety\Policy\Tier::class )
		) {
			return $this->packUnboundError();
		}

		$resolved = $this->resolveAsPackForUser( $user_id );
		if ( null === $resolved ) {
			return $this->packUnboundError();
		}

		foreach ( $this->allowList() as $ability ) {
			if ( ! $resolved->allows( $ability ) ) {
				return $this->packUnboundError();
			}
		}

		if ( ! $resolved->requiresApproval( \Specflux\AgentSafety\Policy\Tier::Irreversible ) ) {
			return $this->packUnboundError();
		}

		return null;
	}

	/**
	 * @return \Specflux\AgentSafety\Packs\Pack|null
	 */
	private function resolveAsPackForUser( int $user_id ): ?\Specflux\AgentSafety\Packs\Pack {
		$resolver = new \Specflux\AgentSafety\Plugin\Support\PackResolver();
		$registry = $resolver->registry();
		$bindings = $registry->bindings();

		foreach ( $this->userTokens( $user_id ) as $token ) {
			if ( isset( $bindings[ $token ] ) ) {
				$pack = $registry->get( $bindings[ $token ] );
				if ( null !== $pack ) {
					return $pack;
				}
			}
		}

		return $registry->resolve( null );
	}

	/**
	 * @return list<string>
	 */
	private function userTokens( int $user_id ): array {
		$tokens = array( 'user:' . $user_id );

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && is_array( $user->roles ) ) {
				foreach ( $user->roles as $role ) {
					if ( is_string( $role ) && '' !== $role ) {
						$tokens[] = 'role:' . $role;
					}
				}
			}
		}

		return $tokens;
	}

	/**
	 * @return WP_Error pack_unbound (400).
	 */
	private function packUnboundError(): WP_Error {
		return new WP_Error(
			'pack_unbound',
			__( 'This pack is not bound to your user. Ask an administrator to bind `user:N` or `role:administrator` to the posts pack.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}
}
