<?php
/**
 * Contains the Shortcode_Conversion class.
 *
 * @package skaut-google-drive-gallery
 */

namespace Sgdg\Admin;

use Sgdg\API_Client;
use Sgdg\Exceptions\Directory_Not_Found_Exception;
use Sgdg\Exceptions\Exception as Sgdg_Exception;
use Sgdg\Frontend\Folder_Path;
use Sgdg\Options;

/**
 * Converts the gallery shortcodes in posts to blocks.
 *
 * The `[sgdg]` shortcode and the classic editor (TinyMCE) button are no longer supported. This conversion runs in the background (in WP-Cron, in small batches):
 * - In posts using the block editor, every shortcode is replaced by a gallery block with the same settings (including the folder IDs, if the plugin is authorized).
 * - From posts using the classic editor, the shortcodes are removed, because a block wouldn't be visible there. Such posts are listed in a notice in the administration.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Shortcode_Conversion {

	/**
	 * The WP-Cron hook of the conversion.
	 *
	 * @var string
	 */
	const HOOK = 'sgdg_shortcode_conversion';

	/**
	 * The option marking the conversion as finished.
	 *
	 * @var string
	 */
	const DONE_OPTION = 'sgdg_shortcode_conversion_done';

	/**
	 * The option storing the ID of the last processed post.
	 *
	 * @var string
	 */
	const CURSOR_OPTION = 'sgdg_shortcode_conversion_cursor';

	/**
	 * The option storing the IDs of the posts the shortcodes were removed from.
	 *
	 * @var string
	 */
	const REMOVED_OPTION = 'sgdg_shortcode_removed_posts';

	/**
	 * The option storing the number of shortcodes converted to blocks.
	 *
	 * @var string
	 */
	const CONVERTED_OPTION = 'sgdg_shortcode_converted_count';

	/**
	 * The option marking the notice as dismissed.
	 *
	 * @var string
	 */
	const DISMISSED_OPTION = 'sgdg_shortcode_notice_dismissed';

	/**
	 * The number of posts processed in one run.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 20;

	/**
	 * The shortcode attributes that are carried over to the block, with their types.
	 *
	 * @var array<string, string>
	 */
	const ATTRIBUTES = array(
		'dir_counts'                 => 'string',
		'dir_ordering_by'            => 'string',
		'dir_ordering_order'         => 'string',
		'dir_prefix'                 => 'string',
		'dir_title_size'             => 'string',
		'grid_height'                => 'int',
		'grid_spacing'               => 'int',
		'image_ordering_by'          => 'string',
		'image_ordering_order'       => 'string',
		'page_autoload'              => 'string',
		'page_size'                  => 'int',
		'preview_activity_indicator' => 'string',
		'preview_arrows'             => 'string',
		'preview_captions'           => 'string',
		'preview_close_button'       => 'string',
		'preview_loop'               => 'string',
		'preview_size'               => 'int',
		'preview_speed'              => 'int',
	);

	/**
	 * Registers the conversion hooks and the notice.
	 */
	public function __construct() {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
		add_action( 'admin_init', array( self::class, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Schedules the conversion if it hasn't finished yet.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( false !== get_option( self::DONE_OPTION, false ) || false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Converts one batch of posts and schedules the next one.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		if ( false !== get_option( self::DONE_OPTION, false ) ) {
			return;
		}

		$cursor = intval( get_option( self::CURSOR_OPTION, 0 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'revision' AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
				$cursor,
				'%' . $wpdb->esc_like( '[sgdg' ) . '%',
				self::BATCH_SIZE
			)
		);

		foreach ( $post_ids as $post_id ) {
			try {
				self::convert_post( intval( $post_id ) );
			} catch ( Sgdg_Exception $e ) {
				// E.g. a rate limit - try again later from this post.
				wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );

				return;
			}

			update_option( self::CURSOR_OPTION, intval( $post_id ), false );
		}

		if ( count( $post_ids ) < self::BATCH_SIZE ) {
			update_option( self::DONE_OPTION, true );
			delete_option( self::CURSOR_OPTION );

			return;
		}

		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Converts the gallery shortcodes in a post.
	 *
	 * @param int $post_id The ID of the post.
	 *
	 * @return bool Whether the post was changed.
	 *
	 * @throws Sgdg_Exception An error when accessing Google Drive (other than a folder not being found).
	 */
	public static function convert_post( $post_id ) {
		global $wpdb;

		$content = strval( get_post_field( 'post_content', $post_id, 'raw' ) );

		if ( false === strpos( $content, '[sgdg' ) ) {
			return false;
		}

		$uses_blocks = false !== strpos( $content, '<!-- wp:' ) && function_exists( 'parse_blocks' );
		$count       = 0;
		$new_content = $uses_blocks
			? self::convert_in_blocks( $content, $count )
			: self::remove_shortcodes( $content, $count );

		if ( 0 === $count || $new_content === $content ) {
			return false;
		}

		// The content is written directly, so that it isn't changed by content filters (which would run without a user in WP-Cron) and the post modification date is kept.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		if ( $uses_blocks ) {
			update_option( self::CONVERTED_OPTION, intval( get_option( self::CONVERTED_OPTION, 0 ) ) + $count, false );
		} else {
			$removed   = (array) get_option( self::REMOVED_OPTION, array() );
			$removed[] = $post_id;
			update_option( self::REMOVED_OPTION, array_values( array_unique( $removed ) ), false );
		}

		return true;
	}

	/**
	 * Shows a notice about the converted and removed shortcodes until it is dismissed.
	 *
	 * @return void
	 */
	public static function notice() {
		if (
			( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) ||
			false !== get_option( self::DISMISSED_OPTION, false )
		) {
			return;
		}

		$removed   = array_map( 'intval', (array) get_option( self::REMOVED_OPTION, array() ) );
		$converted = intval( get_option( self::CONVERTED_OPTION, 0 ) );

		if ( 0 === count( $removed ) && 0 === $converted ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' .
			esc_html__( 'Image and video gallery from Google Drive', 'skaut-google-drive-gallery' ) .
			'</strong></p>';

		if ( 0 < count( $removed ) ) {
			echo '<p>' .
				esc_html__(
					'Support for the gallery shortcode in the classic editor (TinyMCE) has ended with this version. The gallery has been removed from these posts:',
					'skaut-google-drive-gallery'
				) .
				'</p><ul>';

			foreach ( $removed as $post_id ) {
				$title = get_the_title( $post_id );
				$link  = get_edit_post_link( $post_id );
				echo '<li>' .
					( null !== $link
						? '<a href="' . esc_url( $link ) . '">' . esc_html( '' !== $title ? $title : '#' . $post_id ) . '</a>'
						: esc_html( '' !== $title ? $title : '#' . $post_id ) ) .
					'</li>';
			}

			echo '</ul><p>' .
				esc_html__(
					'If you want to keep using the gallery, switch to the block editor and add a Google Drive gallery block.',
					'skaut-google-drive-gallery'
				) .
				'</p>';
		}

		if ( 0 < $converted ) {
			echo '<p>' .
				esc_html(
					sprintf(
						/* translators: %d: The number of galleries */
						_n(
							'%d gallery in posts using the block editor was converted from a shortcode to a block automatically.',
							'%d galleries in posts using the block editor were converted from shortcodes to blocks automatically.',
							$converted,
							'skaut-google-drive-gallery'
						),
						$converted
					)
				) .
				'</p>';
		}

		echo '<p><a href="' .
			esc_url( wp_nonce_url( add_query_arg( 'sgdg_dismiss_shortcode_notice', '1' ), 'sgdg_dismiss_shortcode_notice' ) ) .
			'">' .
			esc_html__( 'Dismiss this notice', 'skaut-google-drive-gallery' ) .
			'</a></p></div>';
	}

	/**
	 * Dismisses the notice.
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['sgdg_dismiss_shortcode_notice'] ) ) {
			return;
		}

		check_admin_referer( 'sgdg_dismiss_shortcode_notice' );

		if ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ) ) {
			update_option( self::DISMISSED_OPTION, true );
		}
	}

	/**
	 * Removes the gallery shortcodes from a post using the classic editor.
	 *
	 * A paragraph containing only the shortcode is removed as a whole.
	 *
	 * @param string $content The post content.
	 * @param int    $count The number of removed shortcodes, passed by reference.
	 *
	 * @return string The new content.
	 */
	private static function remove_shortcodes( $content, &$count ) {
		// A shortcode alone on its line is removed together with the line break(s) after it.
		$content = preg_replace_callback(
			'/(?<![^\n])' . get_shortcode_regex( array( 'sgdg' ) ) . '[ \t]*(?:\r?\n){0,2}/',
			static function ( $matches ) use ( &$count ) {
				// An escaped shortcode (`[[sgdg]]`) is just text.
				if ( '[' === $matches[1] && ']' === $matches[6] ) {
					return $matches[0];
				}

				++$count;

				return '';
			},
			$content
		);

		if ( null === $content ) {
			return '';
		}

		$new_content = preg_replace_callback(
			'/(<p>\s*)?' . get_shortcode_regex( array( 'sgdg' ) ) . '(\s*<\/p>(?:\r?\n)?)?/',
			static function ( $matches ) use ( &$count ) {
				// An escaped shortcode (`[[sgdg]]`) is just text.
				if ( '[' === $matches[2] && ']' === $matches[7] ) {
					return $matches[0];
				}

				++$count;

				if ( '' !== $matches[1] && isset( $matches[8] ) && '' !== $matches[8] ) {
					return '';
				}

				return $matches[1] . ( isset( $matches[8] ) ? $matches[8] : '' );
			},
			$content
		);

		return null === $new_content ? $content : $new_content;
	}

	/**
	 * Replaces the gallery shortcodes in a post using the block editor with gallery blocks.
	 *
	 * A shortcode block or a paragraph containing only the shortcode is replaced as a whole, leaving the rest of the content as it is. A shortcode anywhere else is removed from its block and the gallery block is inserted after the top-level block containing it.
	 *
	 * @param string $content The post content.
	 * @param int    $count The number of converted shortcodes, passed by reference.
	 *
	 * @return string The new content.
	 *
	 * @throws Sgdg_Exception An error when accessing Google Drive (other than a folder not being found).
	 */
	private static function convert_in_blocks( $content, &$count ) {
		$shortcode   = get_shortcode_regex( array( 'sgdg' ) );
		$whole_block = '/<!-- wp:shortcode -->\s*(?P<shortcode>' . $shortcode . ')\s*<!-- \/wp:shortcode -->' .
			'|<!-- wp:paragraph(?: \{[^}]*\})? -->\s*<p(?: [^>]*)?>\s*(?P<paragraph>' . $shortcode . ')\s*<\/p>\s*<!-- \/wp:paragraph -->/';

		$content = preg_replace_callback(
			$whole_block,
			static function ( $matches ) use ( &$count ) {
				$shortcode = '' !== $matches['shortcode'] ? $matches['shortcode'] : $matches['paragraph'];

				if ( '[[' === substr( $shortcode, 0, 2 ) ) {
					return $matches[0];
				}

				++$count;

				return self::shortcode_to_block( $shortcode );
			},
			$content
		);

		if ( null === $content || 0 === preg_match( '/' . $shortcode . '/', $content ) ) {
			return strval( $content );
		}

		// Shortcodes inside other blocks or text.
		$blocks      = array();
		$total_found = 0;

		foreach ( parse_blocks( $content ) as $block ) {
			$found    = array();
			$blocks[] = self::remove_from_block( $block, $found );

			foreach ( $found as $found_shortcode ) {
				++$total_found;
				// The same separator as between the blocks saved by the editor.
				$blocks[] = array(
					'attrs'        => array(),
					'blockName'    => null,
					'innerBlocks'  => array(),
					'innerContent' => array( "\n\n" ),
					'innerHTML'    => "\n\n",
				);
				$blocks[] = parse_blocks( self::shortcode_to_block( $found_shortcode ) )[0];
			}
		}

		if ( 0 === $total_found ) {
			// Only escaped shortcodes are left, keep the content as it is.
			return $content;
		}

		$count += $total_found;

		return serialize_blocks( $blocks );
	}

	/**
	 * Removes the gallery shortcodes from a parsed block and its inner blocks.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 * @param array<string>        $found The removed shortcodes, passed by reference.
	 *
	 * @return array<string, mixed> The block without the shortcodes.
	 */
	private static function remove_from_block( $block, &$found ) {
		$block['innerHTML'] = self::strip_shortcodes( strval( $block['innerHTML'] ), $found );
		// `innerContent` contains the same HTML as `innerHTML` (split around the inner blocks), so its shortcodes aren't collected again.
		$duplicates = array();

		foreach ( $block['innerContent'] as $i => $chunk ) {
			if ( is_string( $chunk ) ) {
				$block['innerContent'][ $i ] = self::strip_shortcodes( $chunk, $duplicates );
			}
		}

		foreach ( $block['innerBlocks'] as $i => $inner_block ) {
			$block['innerBlocks'][ $i ] = self::remove_from_block( $inner_block, $found );
		}

		return $block;
	}

	/**
	 * Removes the gallery shortcodes from a piece of HTML.
	 *
	 * @param string        $html The HTML.
	 * @param array<string> $found The removed shortcodes, passed by reference.
	 *
	 * @return string The HTML without the shortcodes.
	 */
	private static function strip_shortcodes( $html, &$found ) {
		$new_html = preg_replace_callback(
			'/' . get_shortcode_regex( array( 'sgdg' ) ) . '/',
			static function ( $matches ) use ( &$found ) {
				// An escaped shortcode (`[[sgdg]]`) is just text.
				if ( '[' === $matches[1] && ']' === $matches[6] ) {
					return $matches[0];
				}

				$found[] = $matches[0];

				return '';
			},
			$html
		);

		return null === $new_html ? $html : $new_html;
	}

	/**
	 * Creates a gallery block from a shortcode.
	 *
	 * @param string $shortcode The shortcode.
	 *
	 * @return string The serialized block.
	 *
	 * @throws Sgdg_Exception An error when accessing Google Drive (other than a folder not being found).
	 */
	private static function shortcode_to_block( $shortcode ) {
		preg_match( '/' . get_shortcode_regex( array( 'sgdg' ) ) . '/', $shortcode, $matches );
		$atts       = shortcode_parse_atts( isset( $matches[3] ) ? $matches[3] : '' );
		$atts       = is_array( $atts ) ? $atts : array();
		$names      = isset( $atts['path'] ) ? array_values( array_filter( explode( '/', trim( strval( $atts['path'] ), " /\t\n\r\0\x0B" ) ), 'strlen' ) ) : array();
		$attributes = array( 'path' => $names );

		foreach ( self::ATTRIBUTES as $name => $type ) {
			if ( isset( $atts[ $name ] ) ) {
				$attributes[ $name ] = 'int' === $type ? intval( $atts[ $name ] ) : strval( $atts[ $name ] );
			}
		}

		if ( false !== get_option( 'sgdg_access_token', false ) ) {
			$root = Options::$root_path->get();
			$root = end( $root );

			try {
				$folders                = API_Client::execute( array( Folder_Path::from_names( $names, $root ) ) )[0];
				$attributes['path_ids'] = array_column( $folders, 'id' );
			} catch ( Directory_Not_Found_Exception $e ) {
				// The block keeps only the names and shows the error in the editor, just like the shortcode would.
				unset( $e );
			}
		}

		$serialized = function_exists( 'serialize_block_attributes' )
			? serialize_block_attributes( $attributes )
			: wp_json_encode( $attributes );

		return '<!-- wp:skaut-google-drive-gallery/gallery ' . $serialized . ' /-->';
	}
}
