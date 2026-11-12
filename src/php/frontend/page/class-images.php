<?php
/**
 * Contains the Images class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Frontend\Page;

use DateTime;
use Sgdg\API_Facade;
use Sgdg\Exceptions\Internal_Exception;
use Sgdg\Exceptions\Plugin_Not_Authorized_Exception;
use Sgdg\Exceptions\Unsupported_Value_Exception;
use Sgdg\Frontend\API_Fields;
use Sgdg\Frontend\Options_Proxy;
use Sgdg\Frontend\Pagination_Helper;
use Sgdg\Vendor\GuzzleHttp\Promise\PromiseInterface;

/**
 * Contains all the functions used to display images in a gallery.
 */
final class Images {

	/**
	 * Returns a list of images in a directory
	 *
	 * @param string            $parent_id A directory to list items of.
	 * @param Pagination_Helper $pagination_helper An initialized pagination helper.
	 * @param Options_Proxy     $options The configuration of the gallery.
	 *
	 * @return PromiseInterface A promise resolving to a list of images in the format `['id' =>, 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail']`, plus `'name' => 'name'` if image names are enabled.
	 *
	 * @throws Internal_Exception The method was called without an initialized batch.
	 * @throws Plugin_Not_Authorized_Exception Not authorized.
	 * @throws Unsupported_Value_Exception A field that is not supported was passed in `$fields`.
	 */
	public static function get( $parent_id, $pagination_helper, $options ) {
		$show_names = 'true' === $options->get( 'image_names' );
		$fields     = array( 'id', 'thumbnailLink', 'description' );

		if ( $show_names ) {
			$fields[] = 'name';
		}

		if ( 'time' === $options->get_by( 'image_ordering' ) ) {
			$order_by                     = 'name';
			$fields[]                     = 'createdTime';
			$fields['imageMediaMetadata'] = array( 'time' );
		} else {
			$order_by = $options->get( 'image_ordering' );
		}

		return API_Facade::list_images( $parent_id, new API_Fields( $fields ), $pagination_helper, $order_by )->then(
			static function ( $image_response ) use ( $options, $show_names ) {
				$images = array_map(
					static function ( $image ) use ( $options, $show_names ) {
						$ret = array(
							'description' => array_key_exists( 'description', $image )
								? esc_attr( $image['description'] )
								: '',
							'id'          => $image['id'],
							'image'       => substr( $image['thumbnailLink'], 0, -3 ) . $options->get( 'preview_size' ),
							'thumbnail'   => substr( $image['thumbnailLink'], 0, -4 ) .
								'h' .
								floor( 1.25 * $options->get( 'grid_height' ) ),
						);

						if ( $show_names && array_key_exists( 'name', $image ) ) {
							$ret['name'] = self::display_name( $image['name'] );
						}

						return $ret;
					},
					$image_response
				);

				$image_timestamps = array_map(
					static function ( $image ) use ( $options ) {
						return self::extract_timestamp( $image, $options );
					},
					$image_response
				);

				return self::order( $images, $image_timestamps, $options );
			}
		);
	}

	/**
	 * Formats a file name for display in the gallery.
	 *
	 * Removes the file extension and escapes the name for use in HTML.
	 *
	 * @param string $name The raw file name.
	 *
	 * @return string The escaped name without the extension.
	 */
	public static function display_name( $name ) {
		$dot = mb_strrpos( $name, '.' );

		if ( false !== $dot && $dot > 0 ) {
			$name = mb_substr( $name, 0, $dot );
		}

		return esc_html( $name );
	}

	/**
	 * Extracts a timestamp from an image
	 *
	 * @param array<string, mixed> $image An image.
	 * @param Options_Proxy        $options The configuration of the gallery.
	 *
	 * @return int The timestamp.
	 */
	private static function extract_timestamp( $image, $options ) {
		if ( 'time' !== $options->get_by( 'image_ordering' ) ) {
			return time();
		}

		$timestamp = array_key_exists( 'imageMediaMetadata', $image ) &&
			array_key_exists( 'time', $image['imageMediaMetadata'] )
			? DateTime::createFromFormat( 'Y:m:d H:i:s', $image['imageMediaMetadata']['time'] )
			: ( array_key_exists( 'createdTime', $image )
			? DateTime::createFromFormat( 'Y-m-d\TH:i:s.uP', $image['createdTime'] )
			: false );

		return false !== $timestamp ? intval( $timestamp->format( 'U' ) ) : time();
	}

	/**
	 * Orders images.
	 *
	 * @param array<array{id: string, description: string, image: string, thumbnail: string, name?: string, timestamp?: int}> $images A list of images in the format `['id' =>, 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail']`.
	 * @param array<int>                                                                                       $image_timestamps The timestamps for each image.
	 * @param Options_Proxy                                                                                    $options The configuration of the gallery.
	 *
	 * @return array<array{id: string, description: string, image: string, thumbnail: string, name?: string}> An ordered list of images in the format `['id' =>, 'id', 'description' => 'description', 'image' => 'image', 'thumbnail' => 'thumbnail']`.
	 */
	private static function order( $images, $image_timestamps, $options ) {
		if ( 'time' === $options->get_by( 'image_ordering' ) ) {
			uksort(
				$images,
				static function ( $first_index, $second_index ) use ( $image_timestamps, $options ) {
					$asc = $image_timestamps[ $first_index ] - $image_timestamps[ $second_index ];

					return 'ascending' === $options->get_order( 'image_ordering' ) ? $asc : -$asc;
				}
			);
		}

		return array_values( $images );
	}
}
