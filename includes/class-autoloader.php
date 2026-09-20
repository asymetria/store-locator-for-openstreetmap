<?php
/**
 * Maps class names in this plugin's namespace to file paths.
 *
 * Asymetria\StoreLocator\Store_Repository -> includes/class-store-repository.php
 * Asymetria\StoreLocator\Admin\Settings   -> admin/class-settings.php
 *
 * @package Store_Locator_For_OpenStreetMap
 */

namespace Asymetria\StoreLocator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Autoloader' ) ) {

	/**
	 * Resolves this plugin's classes to files under the plugin root.
	 *
	 * The class_exists() guard around the declaration is not decoration. PHP
	 * binds an unguarded class declaration at compile time, so a second copy of
	 * this file — pasted into a theme, shipped inside another plugin — is a
	 * fatal "Cannot declare class ... already in use" that an early return
	 * cannot prevent.
	 */
	class Autoloader {

		/**
		 * The namespace prefix this autoloader answers for.
		 *
		 * @var string
		 */
		private const PREFIX = 'Asymetria\\StoreLocator\\';

		/**
		 * Registers the autoloader with PHP.
		 *
		 * @return void
		 */
		public static function register(): void {
			spl_autoload_register( array( __CLASS__, 'load' ) );
		}

		/**
		 * Loads the file for a class, if the class is ours and the file is there.
		 *
		 * A missing file returns quietly instead of throwing, because an
		 * autoloader that throws does not just fail itself: it aborts the whole
		 * spl_autoload chain, so every other registered autoloader is skipped,
		 * and it turns every legitimate class_exists() probe into a fatal —
		 * including the optional-dependency checks this plugin makes itself.
		 * Nothing is hidden by staying quiet, either. A typo in a class name
		 * still ends in PHP's own "Class not found" fatal, which names the
		 * class, so the second error message would add nothing.
		 *
		 * SLOSM_DIR is checked rather than assumed: register() can run without
		 * the main plugin file having defined it — a test file that calls it
		 * directly, say — and an undefined constant here would turn every later
		 * class lookup anywhere in the process into a fatal.
		 *
		 * @param string $class Fully qualified class name.
		 * @return void
		 */
		public static function load( string $class ): void {
			if ( ! defined( 'SLOSM_DIR' ) ) {
				return;
			}

			$relative = self::path_for( $class );

			if ( null === $relative ) {
				return;
			}

			$file = SLOSM_DIR . $relative;

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		/**
		 * Returns the path relative to the plugin root, or null when the class
		 * is not ours.
		 *
		 * Kept separate from load() precisely so it is testable without touching
		 * the filesystem. The rule: strip the prefix, then every segment is
		 * lowercased with underscores turned into hyphens — the last one
		 * becomes the filename, the rest become directories, and with no
		 * directories left the file lives in includes.
		 *
		 * Directory segments are hyphenated for the same reason filenames are:
		 * WordPress directories are conventionally hyphenated, and lowercasing
		 * alone would leave a sub-namespace such as Rest_Api sitting in a
		 * rest_api/ directory next to a hyphenated admin/.
		 *
		 * @param string $class Fully qualified class name.
		 * @return string|null Path relative to the plugin root, or null.
		 */
		public static function path_for( string $class ): ?string {
			if ( ! str_starts_with( $class, self::PREFIX ) ) {
				return null;
			}

			$relative = substr( $class, strlen( self::PREFIX ) );
			$parts    = explode( '\\', $relative );
			$name     = array_pop( $parts );
			$dir      = empty( $parts ) ? 'includes' : str_replace( '_', '-', strtolower( implode( '/', $parts ) ) );
			$file     = 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';

			return $dir . '/' . $file;
		}
	}
}
