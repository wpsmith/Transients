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

if ( ! class_exists( __NAMESPACE__ . '\Transient' ) ) {
	/**
	 * Class Transient.
	 *
	 * @package WPS\WP
	 */
	abstract class Transient {

		/**
		 * Current transient being registered.
		 *
		 * @var string
		 */
		public $name = '';

		/**
		 * Original class args.
		 *
		 * @var array
		 */
		protected $_args = array();

		/**
		 * Current transient value.
		 *
		 * @var \WP_Post[]
		 */
		protected $value = null;

		/**
		 * Current transient value without checking to see if
		 * the transient has expired.
		 *
		 * @var mixed
		 */
		protected $pre_transient_value;

		/**
		 * Whether to always return the transient value
		 * without checking for expiration.
		 *
		 * @var bool
		 */
		protected $always_return_value = true;

		/**
		 * Default transient timeout, one day.
		 *
		 * @var int
		 */
		protected $timeout = 86400;

		/**
		 * When transient is set to expire.
		 *
		 * @var int
		 */
		protected $transient_expires = null;

		/**
		 * Transient constructor.
		 *
		 * @param array $args Array of arguments.
		 */
		public function __construct( $args = array() ) {
			$defaults = array(
				'name'                => '',
				'timeout'             => 86400, // 1 day
				'value'               => null,
				'pre_transient'       => true,
				'always_return_value' => true,
			);

			// Set args
			$this->_args = wp_parse_args( $args, $defaults );

			// Sanitize set name.
			$this->set_name( $this->_args['name'] );

			// Set timeout.
			$this->set_timeout( $this->_args['timeout'] );

			// Set pre.
			$this->always_return_value = (bool) $this->_args['always_return_value'];

			// Set value.
			if ( ! empty( $this->_args['value'] ) ) {
				$this->set_value( $this->_args['value'] );
			}

		}

		/**
		 * Sets transients hooks into pretransient.
		 *
		 * @return \WP_Error|true
		 */
		public function create() {
			// Make sure $name is set
			if ( '' === $this->name ) {
				return new \WP_Error( 'name-not-set', __( 'Set transient name', 'wps' ), $this
				);
			}

			// Set pre-transient value
			add_filter( 'pre_transient_' . $this->name, array( $this, 'set_pre_transient' ), 10, 2 );

			return true;
		}

		/** SET PROPERTY FUNCTIONS **/

		/**
		 * Sets the transient name.
		 *
		 * @param string $name Transient name.
		 */
		public function set_name( $name ) {

			$this->name = self::truncate_length( $name, 40 );

		}

		/**
		 * Change Timeout from the default 86400.
		 *
		 * @param string $timeout New Default Timeout.
		 */
		public function set_timeout( $timeout ) {

			$this->timeout = absint( $timeout );

		}

		/**
		 * Changes Transient Value & optionally resets transient.
		 *
		 * @param mixed $value           New value.
		 * @param bool  $reset_transient Whether to reset the transient value.
		 */
		public function set_value( $value, $reset_transient = true ) {

			$this->value = $value;

			if ( $reset_transient && '' !== $this->name ) {

				$this->delete();
				$this->set_transient();

			}

		}

		/**
		 * Sets transient's value before checking to see if it expired.
		 *
		 * Hooks into pre_transient_TRANSIENTNAME hook, which is called when
		 * get_transient() is called.
		 *
		 * @see    _get_pre_transient_option()
		 *
		 * @param bool   $pre            The default value to return if the transient does not exist.
		 *                               Any value other than false will short-circuit the retrieval
		 *                               of the transient, and return the returned value.
		 * @param string $transient_name Transient name.
		 *
		 * @return  bool Returns false.
		 */
		public function set_pre_transient( $pre, $transient_name ) {

			if ( $transient_name !== $this->name ) {
				return $pre;
			}

			$this->pre_transient_value = $this->_get_pre_transient_option();

			if ( $this->always_return_value ) {
				if ( $this->is_expired() ) {
					$this->init_cron();
				}

				return $this->pre_transient_value;
			}

			return $pre;

		}

		/** GET PROPERTY FUNCTIONS **/

		/**
		 * Method to be overriden by child class.
		 *
		 * @param bool $fresh Whether to get a fresh value.
		 *
		 * @return mixed
		 */
		abstract protected function get_value( $fresh = false );

		/**
		 * Gets the expires time for transient.
		 *
		 * @return int
		 */
		protected function get_expires_time() {
			if ( ! is_null( $this->transient_expires ) ) {
				return $this->transient_expires;
			}

			$this->transient_expires = $this->_get_transient_expires_option();

			return $this->transient_expires;
		}

		/** CRON FUNCTIONS **/

		/**
		 * Creates a cron job to set the value of the transient.
		 */
		public function init_cron() {

			// Add cron, to update the transient.
			add_action( 'wps_get_transient', array( $this, 'cron_set_transient' ) );
			wp_schedule_single_event( time(), 'wps_get_transient' );

		}

		/**
		 * Cron job to set transient.
		 *
		 * @return mixed
		 */
		public function cron_set_transient() {

			$this->delete();

			return $this->get( 'fresh' );

		}

		/** TRANSIENT FUNCTIONS **/

		/**
		 * Gets transient value straight from DB.
		 *
		 * @access private
		 *
		 * @return mixed Transient value.
		 */
		private function _get_pre_transient_option() {

			return get_option( '_transient_' . $this->name );

		}

		/**
		 * Gets transient expires time straight from DB.
		 *
		 * @access private
		 *
		 * @return int
		 */
		private function _get_transient_expires_option() {

			return intval( get_option( '_transient_timeout_' . $this->name ) );

		}

		/**
		 * Gets the transient value.
		 *
		 * If fresh, this will get a fresh value and reset the transient.
		 *
		 * @param bool $fresh Whether to get fresh transient value.
		 *
		 * @return mixed
		 */
		public function get( $fresh = false ) {

			// Check transient, will return false if expired
			// If expired, get_transient() will delete the transient
			if ( $fresh || false === ( $value = get_transient( $this->name ) ) ) {
				$this->set_transient();
				$value = $this->value;
			}

			// Return value
			return $value;

		}

		/**
		 * Sets the transient value.
		 *
		 */
		public function set_transient() {

			$this->value = $this->get_value( true );
			set_transient( $this->name, $this->value, $this->timeout );

		}

		/** DELETE TRANSIENTS **/

		/**
		 * Deletes this transient.
		 *
		 */
		public function delete() {

			delete_transient( $this->name );

		}

		/**
		 * Clears all transient with a specific prefix.
		 *
		 * @param string $prefix Prefix to remove.
		 *
		 * @return mixed
		 */
		public static function clear_transients( $prefix ) {

			global $wpdb;
			$sql = 'DELETE FROM %1$s WHERE `option_name` LIKE \'%transient_%2$s%\' OR `option_name` LIKE \'%%2$s_transient%\' OR `option_name` LIKE \'%transient_timeout_%2$s%\' OR `option_name` LIKE \'%%2$s_transient_timeout%\'';

			return $wpdb->get_results( $wpdb->prepare( $sql, $wpdb->options, $prefix ) );

		}

		/**
		 * Clears all transients.
		 *
		 * @return mixed
		 */
		public static function clear_all_transients() {

			global $wpdb;
			$sql = "DELETE FROM $wpdb->options WHERE `option_name` LIKE '%transient_%' OR `option_name` LIKE '%transient_timeout_%'";

			return $wpdb->get_results( $sql );

		}

		/** UTILITY FUNCTIONS **/

		/**
		 * Whether the transient has expired.
		 *
		 * @return bool
		 */
		public function is_expired() {

			$timeout = $this->get_expires_time();
			return ( false !== $timeout && $timeout < time() );

		}

		/**
		 * Truncates string based on length of characters.
		 *
		 * This function will truncate a string at a specific length if string is longer.
		 *
		 * @param  string $string String being modified.
		 * @param  int    $length Number of characters to limit string.
		 *
		 * @return string                                 Modified string if string longer than $length.
		 */
		protected static function truncate_length( $string, $length = 40 ) {
			return ( strlen( $string ) > $length ) ? substr( $string, 0, $length ) : $string;
		}

	}
}
