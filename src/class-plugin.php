<?php
/**
 * Main class file for the Composer plugin that will implement a custom installer for Jetpack
 * packages.
 *
 * @see https://getcomposer.org/doc/articles/custom-installers.md
 * @package automattic/jetpack-composer-plugin
 * */

namespace Automattic\Jetpack\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

/**
 * This class is the entry point for the installer plugin. The Composer
 * installation mechanism registers the plugin by calling its activate method.
 */
class Plugin implements PluginInterface, EventSubscriberInterface {

	/**
	 * Installer instance.
	 *
	 * @var Manager|null
	 */
	private $installer;

	/**
	 * Activates the installer plugin at installation time.
	 *
	 * @param Composer    $composer the Composer global instance.
	 * @param IOInterface $io the IO interface global instance.
	 */
	public function activate( Composer $composer, IOInterface $io ) {
		$this->installer = new Manager( $io, $composer );
		$composer->getInstallationManager()->addInstaller( $this->installer );
	}

	/**
	 * Deactivates the installer plugin.
	 *
	 * @param Composer    $composer the Composer global instance.
	 * @param IOInterface $io the IO interface global instance.
	 */
	public function deactivate( Composer $composer, IOInterface $io ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$composer->getInstallationManager()->removeInstaller( $this->installer );
	}

	/**
	 * Uninstalls the installer plugin.
	 *
	 * @param Composer    $composer the Composer global instance.
	 * @param IOInterface $io the IO interface global instance.
	 */
	public function uninstall( Composer $composer, IOInterface $io ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	}

	/**
	 * Tell composer to listen for events and do something with them.
	 *
	 * @return array List of subscribed events.
	 */
	public static function getSubscribedEvents() {
		return array(
			ScriptEvents::POST_INSTALL_CMD => 'generateManifest',
			ScriptEvents::POST_UPDATE_CMD  => 'generateManifest',
		);
	}

	/**
	 * Generate the assets manifest.
	 *
	 * @param Event $event Script event object.
	 */
	public function generateManifest( Event $event ) {
		$composer   = $event->getComposer();
		$filesystem = new Filesystem();
		$io         = $event->getIO();
		$io->info( 'Generating jetpack-library i18n map' );

		$extra = $composer->getPackage()->getExtra();
		if ( isset( $extra['wp-plugin-slug'] ) ) {
			$todomain = $extra['wp-plugin-slug'];
			$totype   = 'plugins';
		} elseif ( isset( $extra['wp-theme-slug'] ) ) {
			$todomain = $extra['wp-theme-slug'];
			$totype   = 'themes';
		} else {
			$io->warning( 'Skipping jetpack-library i18n map generation, .extra.wp-plugin-slug / .extra.wp-theme-slug is not set in composer.json' );
			$filesystem->unlink( 'jetpack_vendor/i18n-map.php' );
			return;
		}

		$data = array(
			'domain'   => $todomain,
			'type'     => $totype,
			'packages' => array(),
		);
		foreach ( $composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package ) {
			if ( $package->getType() !== 'jetpack-library' ) {
				continue;
			}

			$ver = $package->getVersion();
			if ( isset( $extra['branch-alias'][ $ver ] ) ) {
				$ver = $extra['branch-alias'][ $ver ];
			}

			$extra = $package->getExtra();
			if ( empty( $extra['textdomain'] ) ) {
				$io->info( "  {$package->getName()} ($ver): no textdomain set" );
			} else {
				$data['packages'][ $extra['textdomain'] ] = $ver;
				$io->info( "  {$package->getName()} ($ver): textdomain is {$extra['textdomain']}" );
			}
		}

		$code  = "<?php\n";
		$code .= "// i18n-map.php @generated by automattic/jetpack-composer-plugin\n";
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$code .= 'return ' . var_export( $data, true ) . ";\n";

		// Fixup syntax a little.
		$code = str_replace( 'array (', 'array(', $code );
		$code = preg_replace( '/ => \n\s*array\(/', ' => array(', $code );

		$filesystem->filePutContentsIfModified( 'jetpack_vendor/i18n-map.php', $code );
	}

}
