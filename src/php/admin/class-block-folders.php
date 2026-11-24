<?php
/**
 * Contains the Block_Folders class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Admin;

use Sgdg\API_Client;
use Sgdg\API_Facade;
use Sgdg\Exceptions\API_Exception;
use Sgdg\Exceptions\API_Rate_Limit_Exception;
use Sgdg\Exceptions\Cant_Edit_Exception;
use Sgdg\Exceptions\Directory_Not_Found_Exception;
use Sgdg\Exceptions\Internal_Exception;
use Sgdg\Exceptions\Not_Found_Exception;
use Sgdg\Exceptions\Plugin_Not_Authorized_Exception;
use Sgdg\Exceptions\Unsupported_Value_Exception;
use Sgdg\Frontend\API_Fields;
use Sgdg\Frontend\Folder_Path;
use Sgdg\Frontend\Single_Page_Pagination_Helper;
use Sgdg\GET_Helpers;
use Sgdg\Helpers;
use Sgdg\Options;

/**
 * The "list_gallery_folders" AJAX endpoint used by the block editor.
 *
 * It works with folder IDs, so that renaming a folder doesn't break a gallery.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Block_Folders {

	/**
	 * Registers the "list_gallery_folders" AJAX endpoint
	 */
	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_ajax_list_gallery_folders', array( self::class, 'handle_ajax' ) );
	}

	/**
	 * Handles errors for the "list_gallery_folders" AJAX endpoint.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		Helpers::ajax_wrapper( array( self::class, 'ajax_handler_body' ) );
	}

	/**
	 * Actually handles the "list_gallery_folders" AJAX endpoint.
	 *
	 * Resolves a path given either by folder IDs (`path_ids`) or, for blocks created before folder IDs were stored, by folder names (`path`), and lists the subfolders of its last folder. Returns the resolved path and the subfolders, both as lists of `['id' => 'id', 'name' => 'name']`.
	 *
	 * @return void
	 *
	 * @throws API_Exception A wrapped API exception.
	 * @throws API_Rate_Limit_Exception Rate limit exceeded.
	 * @throws Cant_Edit_Exception Insufficient role.
	 * @throws Directory_Not_Found_Exception The directory wasn't found.
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Not_Found_Exception The requested resource couldn't be found.
	 * @throws Plugin_Not_Authorized_Exception Plugin not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function ajax_handler_body() {
		check_ajax_referer( 'sgdg_editor_plugin' );

		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
			throw new Cant_Edit_Exception();
		}

		if ( false === get_option( 'sgdg_access_token', false ) ) {
			throw new Plugin_Not_Authorized_Exception();
		}

		$root_path = Options::$root_path->get();
		$root      = end( $root_path );
		$ids       = GET_Helpers::get_array_variable( 'path_ids' );
		$names     = GET_Helpers::get_array_variable( 'path' );

		$path_promise   = 0 < count( $ids ) || 0 === count( $names )
			? Folder_Path::from_ids( $ids, $root )
			: Folder_Path::from_names( $names, $root );
		$folder_promise = $path_promise->then(
			static function ( $path ) use ( $root ) {
				return API_Facade::list_directories(
					0 < count( $path ) ? end( $path )['id'] : $root,
					new API_Fields( array( 'id', 'name' ) ),
					new Single_Page_Pagination_Helper()
				)->then(
					static function ( $folders ) {
						return array_map(
							static function ( $folder ) {
								return array(
									'id'   => $folder['id'],
									'name' => $folder['name'],
								);
							},
							$folders
						);
					}
				);
			}
		);

		wp_send_json(
			API_Client::execute(
				array(
					'directories' => $folder_promise,
					'path'        => $path_promise,
				)
			)
		);
	}
}
