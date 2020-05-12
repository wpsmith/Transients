<?php
/**
 * Query Transients Manager Class
 *
 * Assists in managing Query Transients.
 *
 * You may copy, distribute and modify the software as long as you track
 * changes/dates in source files. Any modifications to or software including
 * (via compiler) GPL-licensed code must also be made available under the GPL
 * along with build & install instructions.
 *
 * PHP Version 7.2
 *
 * @package    WPS\WP
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2018-2019 Travis Smith
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://wpsmith.net/
 * @since      0.0.1
 */

namespace WPS\WP\Transients\Query;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\QueryTransientManager' ) ) {
	/**
	 * Class QueryTransientManager.
	 *
	 * @package WPS\Transients
	 */
	class QueryTransientManager {

		/**
		 * Current transient being registered.
		 *
		 * @since 1.0.0
		 *
		 * @var string
		 */
		public $name = '';

		/**
		 * Post Type.
		 *
		 * @var string
		 */
		private $post_type = '';

		/**
		 * Query Args.
		 *
		 * @var array
		 */
		private $query_args = array();

		/**
		 * Constructor. Hooks all interactions to initialize the class.
		 *
		 */
		public function __construct( $name, $post_type, $query_args = array() ) {

			$this->name       = $name;
			$this->post_type  = $post_type;
			$this->query_args = $query_args;

			add_action( "save_post_{$this->post_type}", array( $this, 'regenerate_transient' ), 20 );
			add_action( 'delete_post', array( $this, 'delete_transient' ) );

		}

		/**
		 * Clears transient.
		 *
		 * @param int $post_id Post ID.
		 */
		public function delete_transient( $post_id ) {

			// Don't do anything if it is a revision, or if post type is not ours
			if (
				wp_is_post_revision( $post_id ) ||
				! (
					( isset( $_POST['post_type'] ) && $this->post_type === $_POST['post_type'] ) ||
					( $this->post_type === get_post_type( $post_id ) )
				)
			) {
				return;
			}

			// Ok, now delete transient
			delete_transient( $this->get_name( $post_id ) );

		}

		/**
		 * Gets the name of the transients.
		 *
		 * Replaces %s or %d with the post ID.
		 *
		 * @param int $post_id Post ID.
		 *
		 * @return string
		 */
		public function get_name( $post_id ) {
			return sprintf( $this->name, $post_id );
		}

		/**
		 * Initiates regnerating transient asynchronously via cron.
		 *
		 * @param int $post_id Post ID.
		 */
		public function regenerate_transient( $post_id ) {

			$transient = new QueryTransient( array(
				'name'                => $this->get_name( $post_id ),
				'query_args'          => apply_filters( 'wps_query_transient_query_args', $this->query_args, $this->get_name( $post_id ), $post_id ),
				'always_return_value' => true,
				'timeout'             => 500,
			) );
			$transient->init_cron();

		}


	}
}
