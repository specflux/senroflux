<?php
/**
 * Near-miss text lengths are accepted; clear overruns are refused.
 *
 * Live run 2026-09-28-fix1 scenario 1-1: space-bunny resubmitted a plan ten
 * times at 290, 266, 218, 207, 207, 203, 203 characters against a 200 limit —
 * the refusal named the exact length, the model still could not count to it.
 *
 * @package Specflux\SenroFlux\Tests\Tools
 */

declare(strict_types=1);

namespace Specflux\SenroFlux\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\SuggestBriefTool;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WP_Error;

final class LengthToleranceTest extends TestCase {

	private static function plan( string $goal, string $step_text ): array {
		return array(
			'goal'  => $goal,
			'steps' => array(
				array(
					'text'  => $step_text,
					'verbs' => array( 'agsafe-smoke/read' ),
				),
			),
		);
	}

	public function test_a_step_text_slightly_over_the_advertised_limit_is_accepted(): void {
		$result = PlanTools::validateProposePlan( self::plan( 'G', str_repeat( 'x', 207 ) ) );

		$this->assertIsArray( $result );
	}

	public function test_a_step_text_well_over_the_limit_is_refused_with_the_advertised_limit(): void {
		$result = PlanTools::validateProposePlan( self::plan( 'G', str_repeat( 'x', 290 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'step 1 "text" is 290 characters; the limit is 200.', $result->get_error_message() );
	}

	public function test_a_goal_slightly_over_the_advertised_limit_is_accepted(): void {
		$this->assertIsArray( PlanTools::validateProposePlan( self::plan( str_repeat( 'g', 230 ), 'Read' ) ) );
	}

	public function test_an_ask_user_text_slightly_over_the_advertised_limit_is_accepted(): void {
		$result = HarnessTools::validateAskUser(
			array(
				'text'      => str_repeat( 'q', 360 ),
				'rationale' => 'Need the facts.',
			)
		);

		$this->assertIsArray( $result );
	}

	public function test_an_ask_user_text_well_over_the_limit_is_still_refused(): void {
		$result = HarnessTools::validateAskUser(
			array(
				'text'      => str_repeat( 'q', 632 ),
				'rationale' => 'Need the facts.',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '"text" is 632 characters; the limit is 300.', $result->get_error_message() );
	}

	/**
	 * A provider that enforces JSON-schema `maxLength` while generating stops
	 * the model at the cap, cutting the text mid-word (live: a question cut at
	 * 300 characters). The cap is stated in the description and enforced by the
	 * server-side refusals above instead.
	 */
	public function test_no_model_facing_tool_declaration_carries_a_maxlength(): void {
		$declarations = array_merge(
			array( PlanTools::proposePlanDeclaration() ),
			array( PlanTools::proposePlanDeclaration( array( 'pages/media-search', 'pages/create' ) ) ),
			array( HarnessTools::askUserDeclaration() ),
			array_values( SuggestBriefTool::declarations() )
		);

		foreach ( $declarations as $declaration ) {
			$schema = (array) ( $declaration instanceof FunctionDeclaration ? $declaration->getParameters() : $declaration['inputSchema'] );
			$this->assertFalse( self::containsKey( $schema, 'maxLength' ), 'a model-facing schema must not carry maxLength' );
		}
	}

	public function test_no_source_file_declares_a_schema_maxlength(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src' ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$this->assertStringNotContainsString( "'maxLength'", (string) file_get_contents( $file->getPathname() ), $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file.
		}
	}

	/**
	 * @param mixed $node Schema fragment.
	 */
	private static function containsKey( mixed $node, string $key ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		if ( array_key_exists( $key, $node ) ) {
			return true;
		}
		foreach ( $node as $child ) {
			if ( self::containsKey( $child, $key ) ) {
				return true;
			}
		}

		return false;
	}
}
