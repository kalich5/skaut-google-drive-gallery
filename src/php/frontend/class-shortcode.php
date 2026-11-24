<?php
/**
 * Contains the Shortcode class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Frontend;

use Sgdg\API_Client;
use Sgdg\API_Facade;
use Sgdg\Exceptions\API_Exception;
use Sgdg\Exceptions\API_Rate_Limit_Exception;
use Sgdg\Exceptions\Directory_Not_Found_Exception;
use Sgdg\Exceptions\Internal_Exception;
use Sgdg\Exceptions\Not_Found_Exception;
use Sgdg\Exceptions\Plugin_Not_Authorized_Exception;
use Sgdg\Exceptions\Root_Not_Found_Exception;
use Sgdg\Frontend\Options_Proxy;
use Sgdg\Options;
use Sgdg\Script_And_Style_Helpers;
use Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface;
use Sgdg\Vendor\GuzzleHttp\Promise\RejectedPromise;
use const DAY_IN_SECONDS;

/**
 * Contains the rendering of the galleries (used by the block) and the handling of the no longer supported shortcode
 *
 * @phan-constructor-used-for-side-effects
 */
final class Shortcode {

	/**
	 * Registers all the hooks for the shortcode.
	 */
	public function __construct() {
		add_action( 'init', array( self::class, 'init' ) );
	}

	/**
	 * Registers all the scripts and styles used by the galleries and adds the (no longer supported) shortcode.
	 *
	 * @return void
	 */
	public static function init() {
		Script_And_Style_Helpers::register_script(
			'sgdg_gallery_init',
			'frontend/js/shortcode.min.js',
			array( 'jquery', 'sgdg_imagelightbox_script', 'sgdg_imagesloaded', 'sgdg_justified-layout' )
		);
		Script_And_Style_Helpers::register_style( 'sgdg_gallery_css', 'frontend/css/shortcode.min.css' );

		Script_And_Style_Helpers::register_script(
			'sgdg_imagelightbox_script',
			'bundled/imagelightbox.umd.js'
		);
		Script_And_Style_Helpers::register_style( 'sgdg_imagelightbox_style', 'bundled/imagelightbox.css' );
		Script_And_Style_Helpers::register_script(
			'sgdg_imagesloaded',
			'bundled/imagesloaded.pkgd.min.js',
			array( 'jquery' )
		);
		Script_And_Style_Helpers::register_script( 'sgdg_justified-layout', 'bundled/justified-layout.umd.js' );
		add_shortcode( 'sgdg', array( self::class, 'render' ) );
	}

	/**
	 * Renders the shortcode, which is no longer supported.
	 *
	 * Galleries are only supported as blocks. The shortcodes in posts are converted to blocks (or removed from posts using the classic editor) by `Shortcode_Conversion`. A shortcode left elsewhere (e.g. in a widget or a theme template) shows nothing to visitors, so that the raw shortcode isn't printed, and a notice to users who can edit posts.
	 *
	 * @see \Sgdg\Admin\Shortcode_Conversion
	 *
	 * @return string The notice for editors, an empty string for everyone else.
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			return '';
		}

		return '<div class="sgdg-gallery-container"><p>' .
			esc_html__(
				'The Google Drive gallery shortcode is no longer supported. To show the gallery, use the Google Drive gallery block in the block editor.',
				'skaut-google-drive-gallery'
			) .
			'</p></div>';
	}

	/**
	 * Turns the shorcode into HTML.
	 *
	 * @see \Sgdg\Frontend\Options_Proxy
	 *
	 * @param array<string, mixed> $atts A list of option overrides, as documented in the Options_Proxy class plus the `path` attribute, which is an array of directory names, and the optional `path_ids` attribute, which is an array of directory IDs taking precedence over `path`.
	 *
	 * @return string The HTML code for the block.
	 *
	 * @throws API_Exception A wrapped API exception.
	 * @throws API_Rate_Limit_Exception Rate limit exceeded.
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Not_Found_Exception The requested resource couldn't be found.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Root_Not_Found_Exception The root directory of the gallery couldn't be found.
	 */
	public static function html( $atts ) {
		wp_enqueue_script( 'sgdg_imagelightbox_script' );
		wp_enqueue_style( 'sgdg_imagelightbox_style' );
		wp_enqueue_script( 'sgdg_imagesloaded' );
		wp_enqueue_script( 'sgdg_justified-layout' );

		$options = new Options_Proxy( $atts );

		wp_enqueue_script( 'sgdg_gallery_init' );
		Script_And_Style_Helpers::add_script_configuration(
			'sgdg_gallery_init',
			'sgdgShortcodeLocalize',
			array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'breadcrumbs_top'     => esc_html__( 'Gallery', 'skaut-google-drive-gallery' ),
				'empty_gallery'       => esc_html__( 'The gallery is empty.', 'skaut-google-drive-gallery' ),
				'error_header'        => esc_html__(
					'The Image and video gallery from Google Drive plugin has encountered an error. Error message:',
					'skaut-google-drive-gallery'
				),
				'error_trace_header'  => esc_html__( 'Stack trace:', 'skaut-google-drive-gallery' ),
				'grid_height'         => $options->get( 'grid_height' ),
				'grid_spacing'        => $options->get( 'grid_spacing' ),
				'load_more'           => esc_html__( 'Load more', 'skaut-google-drive-gallery' ),
				'page_autoload'       => $options->get( 'page_autoload' ),
				'preview_activity'    => $options->get( 'preview_activity_indicator' ),
				'preview_arrows'      => $options->get( 'preview_arrows' ),
				'preview_captions'    => $options->get( 'preview_captions' ),
				'preview_closebutton' => $options->get( 'preview_close_button' ),
				'preview_quitOnEnd'   => 'true' === $options->get( 'preview_loop' ) ? 'false' : 'true',
				'preview_speed'       => $options->get( 'preview_speed' ),
			)
		);
		wp_enqueue_style( 'sgdg_gallery_css' );
		wp_add_inline_style(
			'sgdg_gallery_css',
			'.sgdg-dir-name {font-size: ' . $options->get( 'dir_title_size' ) . ';}'
		);

		$root_path = Options::$root_path->get();
		$root      = end( $root_path );
		$names     = isset( $atts['path'] ) && is_array( $atts['path'] ) ? $atts['path'] : array();

		if ( isset( $atts['path_ids'] ) && is_array( $atts['path_ids'] ) && count( $atts['path_ids'] ) > 0 ) {
			$root = self::find_dir_by_ids( $root, array_map( 'strval', $atts['path_ids'] ), $names );
		} elseif ( count( $names ) > 0 ) {
			$root_promise = self::find_dir( $root, $names );
			$root         = API_Client::execute( array( $root_promise ) )[0];
		}

		$hash = hash( 'sha256', $root );
		set_transient(
			'sgdg_hash_' . $hash,
			array(
				'overriden' => $options->export_overriden(),
				'root'      => $root,
			),
			DAY_IN_SECONDS
		);

		return '<div class="sgdg-gallery-container" data-sgdg-hash="' .
			$hash .
			'"><div class="sgdg-loading"><div></div></div></div>';
	}

	/**
	 * Finds the ID of the last directory in a path given by directory IDs.
	 *
	 * The IDs are not affected by renaming the directories. If the path is no longer valid (e.g. a directory was deleted and created again), the path given by names is used instead, if there is one.
	 *
	 * @param string        $root The ID of the root directory of the path.
	 * @param array<string> $ids The IDs of the directories forming the path.
	 * @param array<string> $names The names of the directories forming the path, used as a fallback.
	 *
	 * @return string The ID of the directory.
	 *
	 * @throws API_Exception A wrapped API exception.
	 * @throws API_Rate_Limit_Exception Rate limit exceeded.
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Not_Found_Exception The requested resource couldn't be found.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Root_Not_Found_Exception The root directory of the gallery couldn't be found.
	 */
	private static function find_dir_by_ids( $root, array $ids, array $names ) {
		$promise = Folder_Path::from_ids( $ids, $root )->then(
			static function ( $folders ) {
				return end( $folders )['id'];
			},
			static function ( $exception ) use ( $root, $names ) {
				if ( ! ( $exception instanceof Directory_Not_Found_Exception ) ) {
					return new RejectedPromise( $exception );
				}

				if ( 0 === count( $names ) ) {
					return new RejectedPromise( new Root_Not_Found_Exception() );
				}

				return self::find_dir( $root, $names );
			}
		);

		return API_Client::execute( array( $promise ) )[0];
	}

	/**
	 * Finds the ID of a the last directory in `$path` starting from `$root`.
	 *
	 * @param string        $root The ID of the root directory of the path.
	 * @param array<string> $path An array of directory names forming a path starting from $root and ending with the directory whose ID is to be returned.
	 *
	 * @return PromiseInterface The ID of the directory.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 */
	private static function find_dir( $root, array $path ) {
		return API_Facade::get_directory_id( $root, $path[0] )->then(
			static function ( $next_dir_id ) use ( $path ) {
				if ( 1 === count( $path ) ) {
					return $next_dir_id;
				}

				array_shift( $path );

				return self::find_dir( $next_dir_id, $path );
			},
			static function ( $exception ) {
				if ( $exception instanceof Directory_Not_Found_Exception ) {
					return new RejectedPromise(
						new Root_Not_Found_Exception()
					);
				}

				return new RejectedPromise( $exception );
			}
		);
	}
}
