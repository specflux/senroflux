<?php
/**
 * S23: reflection snapshot of the declared `@api` surface (Pack,
 * Api\LayoutVocabulary, the Skill/SkillSource/SetupCheck value types a pack
 * constructs, and the three `@api` filters), compared against the committed
 * `tests/Api/public-surface.json`. A difference fails unless
 * SENROFLUX_API_VERSION changed by the right kind of bump (major for a
 * break, minor for an addition).
 *
 * Regenerate the snapshot after a deliberate, version-bumped change:
 *
 *   SENROFLUX_UPDATE_SURFACE=1 vendor/bin/phpunit --filter PublicSurfaceTest
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Specflux\SenroFlux\Api\LayoutVocabulary;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Setup\SetupCheck;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use UnitEnum;

final class PublicSurfaceTest extends TestCase {

	/** The committed snapshot file. */
	private const SNAPSHOT_PATH = __DIR__ . '/public-surface.json';

	/**
	 * `@api` hook names => their documented argument types, hand-maintained
	 * next to each filter's own hook docblock (there is no runtime
	 * reflection over an `apply_filters()` call's argument list).
	 *
	 * @return array<string,list<string>>
	 */
	private static function apiHooks(): array {
		return array(
			'senroflux_packs'          => array( 'array<string,Pack>' ),
			'senroflux_run_skills'     => array( 'list<Skill>', 'Pack|null', 'string', 'string' ),
			'senroflux_default_budget' => array(
				'array{max_steps:int,max_tool_calls:int,max_tokens:int,max_questions:int,max_plans:int,images:int,refunds:int}',
			),
		);
	}

	/**
	 * Every class whose `@api`-tagged members make up the declared surface,
	 * and how to select its members.
	 *
	 * - 'api_doc': only methods whose own docblock contains the literal
	 *   `@api` (Pack mixes @api and @internal methods on one class).
	 * - 'all_public': every public method (LayoutVocabulary is a pure @api
	 *   facade; nothing non-public exists on it).
	 * - 'constructor_only': just __construct (the value types a pack
	 *   constructs; a pack does not call their other accessors itself).
	 *
	 * @return array<string,string>
	 */
	private static function apiClasses(): array {
		return array(
			Pack::class             => 'api_doc',
			LayoutVocabulary::class => 'all_public',
			Skill::class            => 'constructor_only',
			SetupCheck::class       => 'constructor_only',
		);
	}

	/**
	 * Build the CURRENT surface from live reflection.
	 *
	 * @return array<string,mixed>
	 */
	public static function buildSurface(): array {
		$classes = array();
		foreach ( self::apiClasses() as $class => $mode ) {
			$classes[ $class ] = self::classSurface( $class, $mode );
		}

		// SkillSource is an enum: its cases are the surface, not methods.
		$enum_reflection               = new ReflectionEnum( SkillSource::class );
		$classes[ SkillSource::class ] = array(
			'kind'  => 'enum',
			'cases' => array_map(
				static fn ( $enum_case ) => $enum_case->getName(),
				$enum_reflection->getCases()
			),
		);

		return array(
			'classes' => $classes,
			'hooks'   => self::apiHooks(),
		);
	}

	/**
	 * @param class-string $class_name
	 * @param string       $mode 'api_doc'|'all_public'|'constructor_only'
	 * @return array<string,mixed>
	 */
	private static function classSurface( string $class_name, string $mode ): array {
		$reflection = new ReflectionClass( $class_name );
		$methods    = array();

		if ( 'constructor_only' === $mode ) {
			if ( $reflection->hasMethod( '__construct' ) ) {
				$methods['__construct'] = self::methodSignature( $reflection->getMethod( '__construct' ) );
			}
		} else {
			foreach ( $reflection->getMethods() as $method ) {
				if ( $method->getDeclaringClass()->getName() !== $class_name ) {
					continue; // Only members THIS class declares.
				}
				if ( 'all_public' === $mode && ! $method->isPublic() ) {
					continue;
				}
				if ( 'api_doc' === $mode ) {
					$doc = (string) $method->getDocComment();
					// A method's OWN tag, not a cross-reference to another
					// method's tag in its prose (e.g. gateVerbFor()'s
					// docblock explains it derives from two @api methods
					// while itself staying @internal) — @internal always
					// wins when both literal substrings appear.
					if ( false === strpos( $doc, '@api' ) || false !== strpos( $doc, '@internal' ) ) {
						continue;
					}
				}
				$methods[ $method->getName() ] = self::methodSignature( $method );
			}
			ksort( $methods );
		}

		return array(
			'kind'     => $reflection->isAbstract() ? 'abstract_class' : 'class',
			'is_final' => $reflection->isFinal(),
			'methods'  => $methods,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function methodSignature( ReflectionMethod $method ): array {
		$params = array();
		foreach ( $method->getParameters() as $parameter ) {
			$params[] = self::paramSignature( $parameter );
		}

		return array(
			'static'     => $method->isStatic(),
			'abstract'   => $method->isAbstract(),
			'visibility' => $method->isPublic() ? 'public' : ( $method->isProtected() ? 'protected' : 'private' ),
			'params'     => $params,
			'return'     => self::typeToString( $method->getReturnType() ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function paramSignature( ReflectionParameter $parameter ): array {
		return array(
			'name'        => $parameter->getName(),
			'type'        => self::typeToString( $parameter->getType() ),
			'has_default' => $parameter->isDefaultValueAvailable(),
			'default'     => $parameter->isDefaultValueAvailable() ? self::exportDefault( $parameter->getDefaultValue() ) : null,
			'variadic'    => $parameter->isVariadic(),
		);
	}

	private static function typeToString( mixed $type ): ?string {
		if ( null === $type ) {
			return null;
		}
		if ( $type instanceof ReflectionNamedType ) {
			return ( $type->allowsNull() && 'mixed' !== $type->getName() ? '?' : '' ) . $type->getName();
		}

		return (string) $type;
	}

	private static function exportDefault( mixed $value ): string {
		if ( $value instanceof UnitEnum ) {
			return get_class( $value ) . '::' . $value->name;
		}

		return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- serialising a default value into the reflection snapshot, not debug code.
	}

	/**
	 * The snapshot test itself.
	 */
	public function test_public_surface_matches_snapshot(): void {
		$current = self::buildSurface();

		if ( '1' === (string) getenv( 'SENROFLUX_UPDATE_SURFACE' ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- regenerating a committed test fixture on disk, not a runtime WP operation.
				self::SNAPSHOT_PATH,
				(string) wp_json_encode(
					array(
						'api_version' => SENROFLUX_API_VERSION,
						'surface'     => $current,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				) . "\n"
			);
			$this->markTestSkipped( 'Snapshot regenerated at ' . self::SNAPSHOT_PATH . '; re-run without SENROFLUX_UPDATE_SURFACE to verify.' );
		}

		$this->assertFileExists( self::SNAPSHOT_PATH, 'Run SENROFLUX_UPDATE_SURFACE=1 vendor/bin/phpunit --filter PublicSurfaceTest to create it.' );

		/** @var array{api_version:string,surface:array<string,mixed>} $stored */
		$stored = json_decode( (string) file_get_contents( self::SNAPSHOT_PATH ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.

		$violations = self::gateViolations( $stored['surface'], $current, $stored['api_version'], SENROFLUX_API_VERSION );

		$this->assertSame(
			array(),
			$violations,
			"The @api surface changed without a compatible SENROFLUX_API_VERSION bump:\n" . implode( "\n", $violations )
				. "\n\nIf this change is deliberate: bump SENROFLUX_API_VERSION in senroflux.php (major for a"
				. ' removal/signature break, minor for an addition), then regenerate the snapshot with'
				. ' SENROFLUX_UPDATE_SURFACE=1 vendor/bin/phpunit --filter PublicSurfaceTest.'
		);
	}

	/**
	 * Proof the gate works: a fixture where a signature changes with NO
	 * version bump must be reported as a failure by gateViolations() — the
	 * same function the live snapshot test above uses. This does not touch
	 * the real snapshot; it is pure fixture data.
	 */
	public function test_gate_reports_a_signature_change_with_no_version_bump(): void {
		$old = array(
			'classes' => array(
				'Fixture\\Thing' => array(
					'kind'     => 'class',
					'is_final' => true,
					'methods'  => array(
						'doStuff' => array(
							'static'     => false,
							'abstract'   => false,
							'visibility' => 'public',
							'params'     => array(
								array(
									'name'        => 'input',
									'type'        => 'string',
									'has_default' => false,
									'default'     => null,
									'variadic'    => false,
								),
							),
							'return'     => 'string',
						),
					),
				),
			),
			'hooks'   => array(),
		);

		// The break: doStuff()'s parameter type widened from string to
		// mixed, and the return type changed — a real, breaking signature
		// change, not just a docblock edit.
		$new = $old;
		$new['classes']['Fixture\\Thing']['methods']['doStuff']['params'][0]['type'] = 'mixed';
		$new['classes']['Fixture\\Thing']['methods']['doStuff']['return']            = 'bool';

		$violations = self::gateViolations( $old, $new, '0.3.0', '0.3.0' );

		$this->assertNotSame( array(), $violations, 'A breaking signature change with an UNCHANGED version must be reported.' );

		// The same change WITH a major bump must be clean.
		$this->assertSame( array(), self::gateViolations( $old, $new, '0.3.0', '1.0.0' ) );

		// A minor bump is not enough for a break.
		$this->assertNotSame( array(), self::gateViolations( $old, $new, '0.3.0', '0.4.0' ) );
	}

	/**
	 * An addition (a brand-new method, nothing removed or changed) needs at
	 * least a minor bump, not a major-only or unchanged one.
	 */
	public function test_gate_reports_an_addition_with_no_minor_bump(): void {
		$old = array(
			'classes' => array(
				'Fixture\\Thing' => array(
					'kind'     => 'class',
					'is_final' => true,
					'methods'  => array(),
				),
			),
			'hooks'   => array(),
		);
		$new = $old;
		$new['classes']['Fixture\\Thing']['methods']['newMethod'] = array(
			'static'     => false,
			'abstract'   => false,
			'visibility' => 'public',
			'params'     => array(),
			'return'     => 'void',
		);

		$this->assertNotSame( array(), self::gateViolations( $old, $new, '0.3.0', '0.3.0' ), 'An addition with an unchanged version must be reported.' );
		$this->assertSame( array(), self::gateViolations( $old, $new, '0.3.0', '0.4.0' ), 'A minor bump clears an addition.' );
		$this->assertSame( array(), self::gateViolations( $old, $new, '0.3.0', '1.0.0' ), 'A major bump also clears an addition (tightening the gate is never a break).' );
	}

	/**
	 * @param array<string,mixed> $old_surface
	 * @param array<string,mixed> $new_surface
	 * @return list<string>
	 */
	private static function gateViolations( array $old_surface, array $new_surface, string $old_version, string $new_version ): array {
		$change = self::classifySurfaceChange( $old_surface, $new_surface );
		if ( 'none' === $change ) {
			return array();
		}

		$bump = self::versionBumpKind( $old_version, $new_version );

		if ( 'break' === $change && 'major' !== $bump ) {
			return array( sprintf( 'Surface change is a BREAK (removal or signature change), but the version bump (%s -> %s) is not major.', $old_version, $new_version ) );
		}

		if ( 'addition' === $change && ! in_array( $bump, array( 'major', 'minor' ), true ) ) {
			return array( sprintf( 'Surface change is an ADDITION, but the version bump (%s -> %s) is not at least minor.', $old_version, $new_version ) );
		}

		return array();
	}

	/**
	 * 'break' if anything present in $old is missing or changed in $new;
	 * else 'addition' if $new has anything $old didn't; else 'none'.
	 *
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new_surface
	 */
	private static function classifySurfaceChange( array $old, array $new_surface ): string {
		$has_break    = false;
		$has_addition = false;

		foreach ( array( 'classes', 'hooks' ) as $section ) {
			$old_entries = $old[ $section ] ?? array();
			$new_entries = $new_surface[ $section ] ?? array();

			foreach ( $old_entries as $name => $old_entry ) {
				if ( ! array_key_exists( $name, $new_entries ) ) {
					$has_break = true;
					continue;
				}
				if ( 'classes' === $section ) {
					[ $class_break, $class_addition ] = self::classifyClassChange( $old_entry, $new_entries[ $name ] );
					$has_break                        = $has_break || $class_break;
					$has_addition                     = $has_addition || $class_addition;
				} elseif ( $old_entry !== $new_entries[ $name ] ) {
					$has_break = true;
				}
			}

			foreach ( $new_entries as $name => $new_entry ) {
				if ( ! array_key_exists( $name, $old_entries ) ) {
					$has_addition = true;
				}
			}
		}

		if ( $has_break ) {
			return 'break';
		}

		return $has_addition ? 'addition' : 'none';
	}

	/**
	 * @param array<string,mixed> $old_class
	 * @param array<string,mixed> $new_class
	 * @return array{0:bool,1:bool} [has_break, has_addition]
	 */
	private static function classifyClassChange( array $old_class, array $new_class ): array {
		$has_break    = false;
		$has_addition = false;

		$old_methods = $old_class['methods'] ?? array();
		$new_methods = $new_class['methods'] ?? array();

		foreach ( $old_methods as $name => $old_signature ) {
			if ( ! array_key_exists( $name, $new_methods ) ) {
				$has_break = true;
				continue;
			}
			if ( $old_signature !== $new_methods[ $name ] ) {
				$has_break = true;
			}
		}
		foreach ( $new_methods as $name => $new_signature ) {
			if ( ! array_key_exists( $name, $old_methods ) ) {
				$has_addition = true;
			}
		}

		return array( $has_break, $has_addition );
	}

	/**
	 * @return string 'major'|'minor'|'patch'|'none'|'invalid'
	 */
	private static function versionBumpKind( string $old_version, string $new_version ): string {
		if ( $old_version === $new_version ) {
			return 'none';
		}

		$old_parts = self::versionParts( $old_version );
		$new_parts = self::versionParts( $new_version );
		if ( null === $old_parts || null === $new_parts ) {
			return 'invalid';
		}

		if ( $new_parts[0] > $old_parts[0] ) {
			return 'major';
		}
		if ( $new_parts[0] === $old_parts[0] && $new_parts[1] > $old_parts[1] ) {
			return 'minor';
		}
		if ( $new_parts[0] === $old_parts[0] && $new_parts[1] === $old_parts[1] && $new_parts[2] > $old_parts[2] ) {
			return 'patch';
		}

		return 'invalid';
	}

	/**
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function versionParts( string $version ): ?array {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $version, $m ) ) {
			return null;
		}

		return array( (int) $m[1], (int) $m[2], (int) $m[3] );
	}
}
