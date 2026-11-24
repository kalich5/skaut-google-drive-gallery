<?php
/**
 * Contains the Folder_Path class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Frontend;

use Sgdg\API_Facade;
use Sgdg\Exceptions\Directory_Not_Found_Exception;
use Sgdg\Exceptions\Internal_Exception;
use Sgdg\Exceptions\Plugin_Not_Authorized_Exception;
use Sgdg\Exceptions\Unsupported_Value_Exception;
use Sgdg\Vendor\GuzzleHttp\Promise\FulfilledPromise;
use Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface;

/**
 * Resolves paths to gallery folders, either by folder IDs or by folder names.
 *
 * A path is relative to a root folder and every folder in it must be a subfolder (or a shortcut to a folder) of the previous one. Resolving a path by IDs is not affected by renaming the folders. Checking every step also makes sure that a path can't point to a folder outside of the root.
 */
final class Folder_Path {

	/**
	 * Resolves a path given by folder IDs.
	 *
	 * @param array<string> $ids The IDs of the folders along the path, starting with a subfolder of `$root`.
	 * @param string        $root The ID of the folder the path is relative to.
	 *
	 * @return PromiseInterface A promise resolving to the folders along the path in the format `[['id' => 'id', 'name' => 'name'], ...]`, or rejecting with a `Directory_Not_Found_Exception` if the path is invalid.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function from_ids( array $ids, $root ) {
		return self::resolve( $ids, $root, 'id' );
	}

	/**
	 * Resolves a path given by folder names.
	 *
	 * @param array<string> $names The names of the folders along the path, starting with a subfolder of `$root`.
	 * @param string        $root The ID of the folder the path is relative to.
	 *
	 * @return PromiseInterface A promise resolving to the folders along the path in the format `[['id' => 'id', 'name' => 'name'], ...]`, or rejecting with a `Directory_Not_Found_Exception` if the path is invalid.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function from_names( array $names, $root ) {
		return self::resolve( $names, $root, 'name' );
	}

	/**
	 * Resolves a path one folder at a time.
	 *
	 * @param array<string> $path The IDs or names of the folders along the path.
	 * @param string        $parent_id The ID of the folder the rest of the path is relative to.
	 * @param string        $key Whether `$path` contains IDs (`id`) or names (`name`).
	 *
	 * @return PromiseInterface A promise resolving to the folders along the path.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	private static function resolve( array $path, $parent_id, $key ) {
		if ( 0 === count( $path ) ) {
			return new FulfilledPromise( array() );
		}

		$segment = array_shift( $path );

		return API_Facade::list_directories(
			$parent_id,
			new API_Fields( array( 'id', 'name' ) ),
			new Single_Page_Pagination_Helper()
		)->then(
			/**
			 * Finds the folder among the subfolders and resolves the rest of the path.
			 *
			 * @throws Directory_Not_Found_Exception The folder isn't a subfolder of its parent.
			 */
			static function ( $folders ) use ( $path, $segment, $key ) {
				foreach ( $folders as $folder ) {
					if ( $folder[ $key ] === $segment ) {
						$current = array(
							'id'   => $folder['id'],
							'name' => $folder['name'],
						);

						return self::resolve( $path, $folder['id'], $key )->then(
							static function ( $rest ) use ( $current ) {
								array_unshift( $rest, $current );

								return $rest;
							}
						);
					}
				}

				throw new Directory_Not_Found_Exception( 'name' === $key ? esc_html( $segment ) : null );
			}
		);
	}
}
