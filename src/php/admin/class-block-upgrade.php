<?php
/**
 * Contains the Block_Upgrade class.
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
 * Adds folder IDs to the existing gallery blocks.
 *
 * Blocks created before folder IDs were stored only contain the names of the folders, so they break when a folder is renamed. This upgrade runs in the background (in WP-Cron, in small batches) and adds the IDs of the folders to all such blocks in all posts, pages, reusable blocks etc. Only the comment of the gallery block is changed, the rest of the content is kept as it is. Blocks whose folders can't be found are left unchanged. A block is also upgraded when it is opened in the editor.
 *
 * @phan-constructor-used-for-side-effects
 */
final class Block_Upgrade {

	/**
	 * The WP-Cron hook of the upgrade.
	 *
	 * @var string
	 */
	const HOOK = 'sgdg_block_ids_upgrade';

	/**
	 * The option marking the upgrade as finished.
	 *
	 * @var string
	 */
	const DONE_OPTION = 'sgdg_block_ids_upgrade_done';

	/**
	 * The option storing the ID of the last processed post.
	 *
	 * @var string
	 */
	const CURSOR_OPTION = 'sgdg_block_ids_upgrade_cursor';

	/**
	 * The number of posts processed in one run.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 20;

	/**
	 * Matches the comment of a gallery block. Based on the block parser in WordPress core.
	 *
	 * @var string
	 */
	const BLOCK_REGEX = '/<!--\s+wp:skaut-google-drive-gallery\/gallery\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Registers the upgrade hooks.
	 */
	public function __construct() {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the upgrade if it hasn't finished yet.
	 *
	 * The upgrade needs access to Google Drive, so it only starts once the plugin is authorized.
	 *
	 * @return void
	 */
	public static function schedule() {
		if (
			false !== get_option( self::DONE_OPTION, false ) ||
			false === get_option( 'sgdg_access_token', false ) ||
			false !== wp_next_scheduled( self::HOOK )
		) {
			return;
		}

		wp_schedule_single_event( time(), self::HOOK );
	}

	/**
	 * Upgrades one batch of posts and schedules the next one.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		if ( false !== get_option( self::DONE_OPTION, false ) || false === get_option( 'sgdg_access_token', false ) ) {
			return;
		}

		$cursor = intval( get_option( self::CURSOR_OPTION, 0 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'revision' AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
				$cursor,
				'%' . $wpdb->esc_like( 'wp:skaut-google-drive-gallery/gallery' ) . '%',
				self::BATCH_SIZE
			)
		);

		foreach ( $post_ids as $post_id ) {
			try {
				self::upgrade_post( intval( $post_id ) );
			} catch ( Sgdg_Exception $e ) {
				// E.g. a rate limit or an expired authorization - try again later from this post.
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
	 * Adds folder IDs to the gallery blocks in a post.
	 *
	 * @param int $post_id The ID of the post.
	 *
	 * @return bool Whether the post was changed.
	 *
	 * @throws Sgdg_Exception An error when accessing Google Drive (other than a folder not being found).
	 */
	public static function upgrade_post( $post_id ) {
		global $wpdb;

		$content = strval( get_post_field( 'post_content', $post_id, 'raw' ) );
		$root    = Options::$root_path->get();
		$root    = end( $root );

		$new_content = preg_replace_callback(
			self::BLOCK_REGEX,
			static function ( $matches ) use ( $root ) {
				return self::upgrade_block( $matches, $root );
			},
			$content
		);

		if ( null === $new_content || $new_content === $content ) {
			return false;
		}

		// The content is written directly, so that it isn't changed by content filters (which would run without a user in WP-Cron) and the post modification date is kept.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		return true;
	}

	/**
	 * Adds folder IDs to one gallery block.
	 *
	 * @param array<int|string, string> $matches The matches of `BLOCK_REGEX`.
	 * @param string                    $root The ID of the root folder of the plugin.
	 *
	 * @return string The new block comment, or the original one if it doesn't need to or can't be upgraded.
	 *
	 * @throws Sgdg_Exception An error when accessing Google Drive (other than a folder not being found).
	 */
	private static function upgrade_block( $matches, $root ) {
		$attributes = array();

		if ( isset( $matches['attrs'] ) && '' !== $matches['attrs'] ) {
			$attributes = json_decode( $matches['attrs'], true );
		}

		if ( ! is_array( $attributes ) || array_key_exists( 'path_ids', $attributes ) ) {
			return $matches[0];
		}

		$names = isset( $attributes['path'] ) && is_array( $attributes['path'] )
			? array_map( 'strval', $attributes['path'] )
			: array();

		try {
			$folders = API_Client::execute( array( Folder_Path::from_names( $names, $root ) ) )[0];
		} catch ( Directory_Not_Found_Exception $e ) {
			return $matches[0];
		}

		$attributes['path_ids'] = array_column( $folders, 'id' );
		$serialized             = function_exists( 'serialize_block_attributes' )
			? serialize_block_attributes( $attributes )
			: wp_json_encode( $attributes );

		return '<!-- wp:skaut-google-drive-gallery/gallery ' .
			$serialized .
			' ' .
			( isset( $matches['void'] ) && '' !== $matches['void'] ? '/' : '' ) .
			'-->';
	}
}
