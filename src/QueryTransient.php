<?php
/**
 * AsyncTransients Class
 *
 * Provides an interface for an improved experience with WordPress transients.
 * Implementation of async transients for WordPress. If transients are expired,
 * stale data is served, and the transient is queued up to be regenerated on shutdown.
 *
 * You may copy, distribute and modify the software as long as you track changes/dates in source files.
 * Any modifications to or software including (via compiler) GPL-licensed code must also be made
 * available under the GPL along with build & install instructions.
 *
 * @package    WPS\WP
 * @author     Chris Marslender
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2018-2019 Travis Smith, Chris Marslender
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License v2
 * @link       https://github.com/wpsmith/WPS
 * @since      File available since Release 1.0.0
 */

namespace WPS\WP\Transients;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\QueryTransient' ) ) {
	/**
	 * Class QueryTransient.
	 *
	 * @package WPS\WP
	 */
	class QueryTransient extends Transient {

		/**
		 * Post type.
		 *
		 * @var string
		 */
		protected $post_type = 'post';

		/**
		 * Query args for query transient.
		 *
		 * @var array
		 */
		public $args = array();

		/**
		 * QueryTransient constructor.
		 *
		 * @param array $args Array of arguments.
		 */
		public function __construct( $args = array() ) {

			$defaults = array(
				'post_type'  => self::get_post_type( $args ),
				'query_args' => array(),
			);

			// Set args
			$args = wp_parse_args( $args, $defaults );

			// Let 'er run!
			parent::__construct( $args );

		}

		/**
		 * Sets transients hooks into pretransient.
		 */
		public function create() {

			// Set query_args & set value.
			$query_args = array();
			if ( isset( $this->_args['query_args'] ) ) {
				$query_args = $this->_args['query_args'];
			} elseif ( isset( $this->_args['args'] ) ) {
				$query_args = $this->_args['args'];
			}
			$this->set_query_args( $query_args );

			// Clear transient.
			add_action( 'delete_post', array( $this, 'clear_transient' ) );
			add_action( 'save_post_' . $this->post_type, array( $this, 'regenerate_transient' ) );

			// Let 'er run!
			parent::create();
		}

		/** SET PROPERTY FUNCTIONS **/

		/**
		 * Sets the query arguments.
		 *
		 * @param array $args \WP_Query arguments.
		 */
		public function set_query_args( $args ) {

			$this->args = wp_parse_args( $args, $this->args );

		}

		/** GET PROPERTY FUNCTIONS **/

		/**
		 * Gets the value of the query.
		 *
		 * Overrides the abstract method & implements Transient.
		 *
		 * @param bool $fresh Whether to get fresh value.
		 *
		 * @return mixed|\WP_Post[]|\WP_Query
		 */
		protected function get_value( $fresh = false ) {

			if ( $fresh || is_null( $this->value ) ) {
				$this->value = new \WP_Query( $this->args );
				$this->value->get_posts();
			}

			return $this->value;

		}

		/**
		 * Whether the post is the correct post type.
		 *
		 * @param int $post_id Post ID.
		 *
		 * @return bool
		 */
		protected function is_transient( $post_id ) {
			return (
				( isset( $_POST['post_type'] ) && $this->post_type === $_POST['post_type'] ) ||
				( $this->post_type === get_post_type( $post_id ) )
			);
		}

		/**
		 * Clears transient.
		 *
		 * @param int      $post_id Post ID.
		 */
		public function clear_transient( $post_id ) {

			// Don't do anything if it is a revision, or if post type is not ours
			if ( wp_is_post_revision( $post_id ) || ! $this->is_transient( $post_id ) ) {
				return;
			}

			// Ok, now delete transient
			$this->delete();

		}

		/**
		 * Regenerates the transient via cron job.
		 *
		 * @param int $post_id Post ID.
		 */
		public function regenerate_transient( $post_id ) {

			// Don't do anything if it is a revision, or if post type is not ours
			if ( wp_is_post_revision( $post_id ) ) {
				return;
			}

			// Now, regenerate asynchronously.
			$this->init_cron();

		}

		/** UTILITY FUNCTIONS **/

		/**
		 * Gets the post type from the query args.
		 *
		 * @param array $args Array of query arguments.
		 *
		 * @return string
		 */
		public static function get_post_type( $args ) {
			if ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) {
				return $args['post_type'];
			}

			if ( isset( $args['query_args'] ) && isset( $args['query_args']['post_type'] ) && '' !== $args['query_args']['post_type'] ) {
				return $args['query_args']['post_type'];
			}

			if ( isset( $args['args'] ) && isset( $args['args']['post_type'] ) && '' !== $args['args']['post_type'] ) {
				return $args['args']['post_type'];
			}

			return 'post';
		}

	}
}
