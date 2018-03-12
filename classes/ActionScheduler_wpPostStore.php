<?php

/**
 * Class ActionScheduler_wpPostStore
 */
class ActionScheduler_wpPostStore extends ActionScheduler_Store {
	const POST_TYPE = 'scheduled-action';
	const GROUP_TAXONOMY = 'action-group';
	const SCHEDULE_META_KEY = '_action_manager_schedule';

	/** @var DateTimeZone */
	protected $local_timezone = NULL;

	public function save_action( ActionScheduler_Action $action, DateTime $date = NULL ){
		try {
			$post_array = $this->create_post_array( $action, $date );
			$post_id = $this->save_post_array( $post_array );
			$this->save_post_schedule( $post_id, $action->get_schedule() );
			$this->save_action_group( $post_id, $action->get_group() );
			do_action( 'action_scheduler_stored_action', $post_id );
			return $post_id;
		} catch ( Exception $e ) {
			throw new RuntimeException( sprintf( __('Error saving action: %s', 'action-scheduler'), $e->getMessage() ), 0 );
		}
	}

	protected function create_post_array( ActionScheduler_Action $action, DateTime $date = NULL ) {
		$post = array(
			'post_type' => self::POST_TYPE,
			'post_title' => $action->get_hook(),
			'post_content' => json_encode($action->get_args()),
			'post_status' => ( $action->is_finished() ? 'publish' : 'pending' ),
			'post_date_gmt' => $this->get_timestamp($action, $date),
			'post_date' => $this->get_local_timestamp($action, $date),
		);
		return $post;
	}

	protected function get_timestamp( ActionScheduler_Action $action, DateTime $date = NULL ) {
		$next = is_null($date) ? $action->get_schedule()->next() : $date;
		if ( !$next ) {
			throw new InvalidArgumentException(__('Invalid schedule. Cannot save action.', 'action-scheduler'));
		}
		$next->setTimezone(new DateTimeZone('UTC'));
		return $next->format('Y-m-d H:i:s');
	}

	protected function get_local_timestamp( ActionScheduler_Action $action, DateTime $date = NULL ) {
		$next = is_null($date) ? $action->get_schedule()->next() : $date;
		if ( !$next ) {
			throw new InvalidArgumentException(__('Invalid schedule. Cannot save action.', 'action-scheduler'));
		}
		$next->setTimezone($this->get_local_timezone());
		return $next->format('Y-m-d H:i:s');
	}

	protected function get_local_timezone() {
		return ActionScheduler_TimezoneHelper::get_local_timezone();
	}

	protected function save_post_array( $post_array ) {
		add_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 1 );
		$post_id = wp_insert_post($post_array);
		remove_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 1 );

		if ( is_wp_error($post_id) || empty($post_id) ) {
			throw new RuntimeException(__('Unable to save action.', 'action-scheduler'));
		}
		return $post_id;
	}

	public function filter_insert_post_data( $postdata ) {
		if ( $postdata['post_type'] == self::POST_TYPE ) {
			$postdata['post_author'] = 0;
			if ( $postdata['post_status'] == 'future' ) {
				$postdata['post_status'] = 'publish';
			}
		}
		return $postdata;
	}

	protected function save_post_schedule( $post_id, $schedule ) {
		update_post_meta( $post_id, self::SCHEDULE_META_KEY, $schedule );
	}

	protected function save_action_group( $post_id, $group ) {
		if ( empty($group) ) {
			wp_set_object_terms( $post_id, array(), self::GROUP_TAXONOMY, FALSE );
		} else {
			wp_set_object_terms( $post_id, array($group), self::GROUP_TAXONOMY, FALSE );
		}
	}

	public function fetch_action( $action_id ) {
		$post = $this->get_post( $action_id );
		if ( empty($post) || $post->post_type != self::POST_TYPE ) {
			return $this->get_null_action();
		}
		return $this->make_action_from_post($post);
	}

	protected function get_post( $action_id ) {
		if ( empty($action_id) ) {
			return NULL;
		}
		return get_post($action_id);
	}

	protected function get_null_action() {
		return new ActionScheduler_NullAction();
	}

	protected function make_action_from_post( $post ) {
		$hook = $post->post_title;
		$args = json_decode( $post->post_content, true );
		$schedule = get_post_meta( $post->ID, self::SCHEDULE_META_KEY, true );
		if ( empty($schedule) ) {
			$schedule = new ActionScheduler_NullSchedule();
		}
		$group = wp_get_object_terms( $post->ID, self::GROUP_TAXONOMY, array('fields' => 'names') );
		$group = empty( $group ) ? '' : reset($group);

		return ActionScheduler::factory()->get_stored_action( $this->get_action_status_by_post_status( $post->post_status ), $hook, $args, $schedule, $group );
	}

	/**
	 * @param string $post_status
	 *
	 * @return string
	 */
	protected function get_action_status_by_post_status( $post_status ) {

		switch ( $post_status ) {
			case 'publish' :
				$action_status = self::STATUS_COMPLETE;
				break;
			case 'trash' :
				$action_status = self::STATUS_CANCELED;
				break;
			default :
				if ( ! array_key_exists( $post_status, $this->get_status_labels() ) ) {
					throw new InvalidArgumentException( sprintf( 'Invalid post status: "%s". No matching action status available.', $post_status ) );
				}
				$action_status = $post_status;
				break;
		}

		return $action_status;
	}

	/**
	 * @param string $action_status
	 *
	 * @return string
	 */
	protected function get_post_status_by_action_status( $action_status ) {

		switch ( $action_status ) {
			case self::STATUS_COMPLETE :
				$post_status = 'publish';
				break;
			case self::STATUS_CANCELED :
				$post_status = 'trash';
				break;
			default :
				if ( ! array_key_exists( $action_status, $this->get_status_labels() ) ) {
					throw new InvalidArgumentException( sprintf( 'Invalid action status: "%s".', $action_status ) );
				}
				$post_status = $action_status;
				break;
		}

		return $post_status;
	}

	/**
	 * @param string $hook
	 * @param array $params
	 *
	 * @return string ID of the next action matching the criteria or NULL if not found
	 */
	public function find_action( $hook, $params = array() ) {
		$params = wp_parse_args( $params, array(
			'args' => NULL,
			'status' => ActionScheduler_Store::STATUS_PENDING,
			'group' => '',
		));
		/** @var wpdb $wpdb */
		global $wpdb;
		$query = "SELECT p.ID FROM {$wpdb->posts} p";
		$args = array();
		if ( !empty($params['group']) ) {
			$query .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID";
			$query .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id";
			$query .= " INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id AND t.slug=%s";
			$args[] = $params['group'];
		}
		$query .= " WHERE p.post_title=%s";
		$args[] = $hook;
		$query .= " AND p.post_type=%s";
		$args[] = self::POST_TYPE;
		if ( !is_null($params['args']) ) {
			$query .= " AND p.post_content=%s";
			$args[] = json_encode($params['args']);
		}

		if ( ! empty( $params['status'] ) ) {
			$query .= " AND p.post_status=%s";
			$args[] = $this->get_post_status_by_action_status( $params['status'] );
		}

		switch ( $params['status'] ) {
			case self::STATUS_COMPLETE:
			case self::STATUS_RUNNING:
			case self::STATUS_FAILED:
				$order = 'DESC'; // Find the most recent action that matches
				break;
			case self::STATUS_PENDING:
			default:
				$order = 'ASC'; // Find the next action that matches
				break;
		}
		$query .= " ORDER BY post_date_gmt $order LIMIT 1";

		$query = $wpdb->prepare( $query, $args );

		$id = $wpdb->get_var($query);
		return $id;
	}

	/**
	 * Returns the SQL statement to query (or count) actions.
	 *
	 * @param array $query Filtering options
	 * @param string $select_or_count  Whether the SQL should select and return the IDs or just the row count
	 *
	 * @return string SQL statement. The returned SQL is already properly escaped.
	 */
	protected function get_query_actions_sql( array $query, $select_or_count = 'select' ) {
		$query = wp_parse_args( $query, array(
			'hook' => '',
			'args' => NULL,
			'date' => NULL,
			'date_compare' => '<=',
			'modified' => NULL,
			'modified_compare' => '<=',
			'group' => '',
			'status' => '',
			'claimed' => NULL,
			'per_page' => 5,
			'offset' => 0,
			'orderby' => 'date',
			'order' => 'ASC',
		) );

		/** @var wpdb $wpdb */
		global $wpdb;
		$sql  = ( 'count' === $select_or_count ) ? 'SELECT count(p.ID)' : 'SELECT p.ID ';
		$sql .= "FROM {$wpdb->posts} p";
		$sql_params = array();
		if ( !empty($query['group']) ) {
			$sql .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID";
			$sql .= " INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id";
			$sql .= " INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id AND t.slug=%s";
			$sql_params[] = $query['group'];
		}
		$sql .= " WHERE post_type=%s";
		$sql_params[] = self::POST_TYPE;
		if ( $query['hook'] ) {
			$sql .= " AND p.post_title=%s";
			$sql_params[] = $query['hook'];
		}
		if ( !is_null($query['args']) ) {
			$sql .= " AND p.post_content=%s";
			$sql_params[] = json_encode($query['args']);
		}

		if ( ! empty( $query['status'] ) ) {
			$sql .= " AND p.post_status=%s";
			$sql_params[] = $this->get_post_status_by_action_status( $query['status'] );
		}

		if ( $query['date'] instanceof DateTime ) {
			$date = clone $query['date'];
			$date->setTimezone( new DateTimeZone('UTC') );
			$date_string = $date->format('Y-m-d H:i:s');
			$comparator = $this->validate_sql_comparator($query['date_compare']);
			$sql .= " AND p.post_date_gmt $comparator %s";
			$sql_params[] = $date_string;
		}

		if ( $query['modified'] instanceof DateTime ) {
			$modified = clone $query['modified'];
			$modified->setTimezone( new DateTimeZone('UTC') );
			$date_string = $modified->format('Y-m-d H:i:s');
			$comparator = $this->validate_sql_comparator($query['modified_compare']);
			$sql .= " AND p.post_modified_gmt $comparator %s";
			$sql_params[] = $date_string;
		}

		if ( $query['claimed'] === TRUE ) {
			$sql .= " AND p.post_password != ''";
		} elseif ( $query['claimed'] === FALSE ) {
			$sql .= " AND p.post_password = ''";
		} elseif ( !is_null($query['claimed']) ) {
			$sql .= " AND p.post_password = %s";
			$sql_params[] = $query['claimed'];
		}

		if ( 'select' === $select_or_count ) {
			switch ( $query['orderby'] ) {
				case 'hook':
					$orderby = 'p.title';
					break;
				case 'group':
					$orderby = 't.name';
					break;
				case 'modified':
					$orderby = 'p.post_modified';
					break;
				case 'date':
				default:
					$orderby = 'p.post_date_gmt';
					break;
			}
			if ( 'ASC' === strtoupper( $query['order'] ) ) {
				$order = 'ASC';
			} else {
				$order = 'DESC';
			}
			$sql .= " ORDER BY $orderby $order";
			if ( $query['per_page'] > 0 ) {
				$sql .= " LIMIT %d, %d";
				$sql_params[] = $query['offset'];
				$sql_params[] = $query['per_page'];
			}
		}

		return $wpdb->prepare( $sql, $sql_params );
	}

	/**
	 * Similar method to query_actions() but returns the number of matching rows
	 *
	 * @param array $query
	 * @return int
	 */
	public function query_actions_count( $query = array() ) {
		/** @var wpdb $wpdb */
		global $wpdb;

		return $wpdb->get_var( $this->get_query_actions_sql( $query, 'count' ) );
	}

	/**
	 * @param array $query
	 * @return array The IDs of actions matching the query
	 */
	public function query_actions( $query = array() ) {
		/** @var wpdb $wpdb */
		global $wpdb;

		return $wpdb->get_col( $this->get_query_actions_sql( $query, 'select' ) );
	}

	/**
	 * Get a count of all actions in the store, grouped by status
	 *
	 * @return array
	 */
	public function actions_count() {

		$actions_count_by_status = array();
		$action_stati_and_labels = $this->get_status_labels();
		$posts_count_by_status   = (array) wp_count_posts( self::POST_TYPE, 'readable' );

		foreach ( $posts_count_by_status as $post_status_name => $count ) {

			try {
				$action_status_name = $this->get_action_status_by_post_status( $post_status_name );
			} catch ( Exception $e ) {
				// Ignore any post statuses that aren't for actions
				continue;
			}
			if ( array_key_exists( $action_status_name, $action_stati_and_labels ) ) {
				$actions_count_by_status[ $action_status_name ] = $count;
			}
		}

		return $actions_count_by_status;
	}

	/**
	 * @param string $action_id
	 *
	 * @throws InvalidArgumentException
	 * @return void
	 */
	public function cancel_action( $action_id ) {
		$post = get_post($action_id);
		if ( empty($post) || ($post->post_type != self::POST_TYPE) ) {
			throw new InvalidArgumentException(sprintf(__('Unidentified action %s', 'action-scheduler'), $action_id));
		}
		do_action( 'action_scheduler_canceled_action', $action_id );
		wp_trash_post($action_id);
	}

	public function delete_action( $action_id ) {
		$post = get_post($action_id);
		if ( empty($post) || ($post->post_type != self::POST_TYPE) ) {
			throw new InvalidArgumentException(sprintf(__('Unidentified action %s', 'action-scheduler'), $action_id));
		}
		do_action( 'action_scheduler_deleted_action', $action_id );
		wp_delete_post($action_id, TRUE);
	}

	/**
	 * @param string $action_id
	 *
	 * @throws InvalidArgumentException
	 * @return DateTime The date the action is schedule to run, or the date that it ran.
	 */
	public function get_date( $action_id ) {
		$date = $this->get_date_gmt( $action_id );
		return $date->setTimezone( $this->get_local_timezone() );
	}

	/**
	 * @param string $action_id
	 *
	 * @throws InvalidArgumentException
	 * @return DateTime The date the action is schedule to run, or the date that it ran.
	 */
	public function get_date_gmt( $action_id ) {
		$post = get_post($action_id);
		if ( empty($post) || ($post->post_type != self::POST_TYPE) ) {
			throw new InvalidArgumentException(sprintf(__('Unidentified action %s', 'action-scheduler'), $action_id));
		}
		if ( $post->post_status == 'publish' ) {
			return as_get_datetime_object($post->post_modified_gmt);
		} else {
			return as_get_datetime_object($post->post_date_gmt);
		}
	}

	/**
	 * @param int $max_actions
	 * @param DateTime $before_date Jobs must be schedule before this date. Defaults to now.
	 *
	 * @return ActionScheduler_ActionClaim
	 */
	public function stake_claim( $max_actions = 10, DateTime $before_date = NULL ){
		$claim_id = $this->generate_claim_id();
		$this->claim_actions( $claim_id, $max_actions, $before_date );
		$action_ids = $this->find_actions_by_claim_id( $claim_id );
		return new ActionScheduler_ActionClaim( $claim_id, $action_ids );
	}

	/**
	 * @return int
	 */
	public function get_claim_count(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT post_password) FROM {$wpdb->posts} WHERE post_password != '' AND post_type = %s AND post_status IN ('in-progress','pending')";
		$sql = $wpdb->prepare( $sql, array( self::POST_TYPE ) );

		return $wpdb->get_var( $sql );
	}

	protected function generate_claim_id() {
		$claim_id = md5(microtime(true) . rand(0,1000));
		return substr($claim_id, 0, 20); // to fit in db field with 20 char limit
	}

	/**
	 * @param string $claim_id
	 * @param int $limit
	 * @param DateTime $before_date Should use UTC timezone.
	 * @return int The number of actions that were claimed
	 * @throws RuntimeException
	 */
	protected function claim_actions( $claim_id, $limit, DateTime $before_date = NULL ) {
		/** @var wpdb $wpdb */
		global $wpdb;

		$date = is_null($before_date) ? as_get_datetime_object() : clone $before_date;
		// can't use $wpdb->update() because of the <= condition, using post_modified to take advantage of indexes
		$sql = "UPDATE {$wpdb->posts} SET post_password = %s, post_modified_gmt = %s, post_modified = %s WHERE post_type = %s AND post_status = %s AND post_password = '' AND post_date_gmt <= %s ORDER BY menu_order ASC, post_date_gmt ASC LIMIT %d";
		$sql = $wpdb->prepare( $sql, array( $claim_id, current_time('mysql', true), current_time('mysql'), self::POST_TYPE, 'pending', $date->format('Y-m-d H:i:s'), $limit ) );
		$rows_affected = $wpdb->query($sql);
		if ( $rows_affected === false ) {
			throw new RuntimeException(__('Unable to claim actions. Database error.', 'action-scheduler'));
		}
		return (int)$rows_affected;
	}

	/**
	 * @param string $claim_id
	 * @return array
	 */
	public function find_actions_by_claim_id( $claim_id ) {
		/** @var wpdb $wpdb */
		global $wpdb;
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_password = %s";
		$sql = $wpdb->prepare( $sql, array( self::POST_TYPE, $claim_id ) );
		$action_ids = $wpdb->get_col( $sql );
		return $action_ids;
	}

	public function release_claim( ActionScheduler_ActionClaim $claim ) {
		$action_ids = $this->find_actions_by_claim_id( $claim->get_id() );
		if ( empty($action_ids) ) {
			return; // nothing to do
		}
		$action_id_string = implode(',', array_map('intval', $action_ids));
		/** @var wpdb $wpdb */
		global $wpdb;
		$sql = "UPDATE {$wpdb->posts} SET post_password = '' WHERE ID IN ($action_id_string) AND post_password = %s";
		$sql = $wpdb->prepare( $sql, array( $claim->get_id() ) );
		$result = $wpdb->query($sql);
		if ( $result === false ) {
			throw new RuntimeException( sprintf( __('Unable to unlock claim %s. Database error.', 'action-scheduler'), $claim->get_id() ) );
		}
	}

	/**
	 * @param string $action_id
	 *
	 * @return void
	 */
	public function unclaim_action( $action_id ) {
		/** @var wpdb $wpdb */
		global $wpdb;
		$sql = "UPDATE {$wpdb->posts} SET post_password = '' WHERE ID = %d AND post_type = %s";
		$sql = $wpdb->prepare( $sql, $action_id, self::POST_TYPE );
		$result = $wpdb->query($sql);
		if ( $result === false ) {
			throw new RuntimeException( sprintf( __('Unable to unlock claim on action %s. Database error.', 'action-scheduler'), $action_id ) );
		}
	}

	public function mark_failure( $action_id ) {
		/** @var wpdb $wpdb */
		global $wpdb;
		$sql = "UPDATE {$wpdb->posts} SET post_status = %s WHERE ID = %d AND post_type = %s";
		$sql = $wpdb->prepare( $sql, self::STATUS_FAILED, $action_id, self::POST_TYPE );
		$result = $wpdb->query($sql);
		if ( $result === false ) {
			throw new RuntimeException( sprintf( __('Unable to mark failure on action %s. Database error.', 'action-scheduler'), $action_id ) );
		}
	}

	public function get_status( $action_id ) {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$sql = $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID=%d AND post_type=%s", $action_id, self::POST_TYPE );
		$status = $wpdb->get_var( $sql );

		if ( $status === null ) {
			throw new \InvalidArgumentException( __( 'Invalid action ID. No status found.', 'action-scheduler' ) );
		}

		return $this->get_action_status_by_post_status( $status );
	}

	/**
	 * @param string $action_id
	 *
	 * @return void
	 */
	public function log_execution( $action_id ) {
		/** @var wpdb $wpdb */
		global $wpdb;

		$sql = "UPDATE {$wpdb->posts} SET menu_order = menu_order+1, post_status=%s, post_modified_gmt = %s, post_modified = %s WHERE ID = %d AND post_type = %s";
		$sql = $wpdb->prepare( $sql, self::STATUS_RUNNING, current_time('mysql', true), current_time('mysql'), $action_id, self::POST_TYPE );
		$wpdb->query($sql);
	}


	public function mark_complete( $action_id ) {
		$post = get_post($action_id);
		if ( empty($post) || ($post->post_type != self::POST_TYPE) ) {
			throw new InvalidArgumentException(sprintf(__('Unidentified action %s', 'action-scheduler'), $action_id));
		}
		add_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 1 );
		$result = wp_update_post(array(
			'ID' => $action_id,
			'post_status' => 'publish',
		), TRUE);
		remove_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 1 );
		if ( is_wp_error($result) ) {
			throw new RuntimeException($result->get_error_message());
		}
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function init() {
		$post_type_registrar = new ActionScheduler_wpPostStore_PostTypeRegistrar();
		$post_type_registrar->register();

		$post_status_registrar = new ActionScheduler_wpPostStore_PostStatusRegistrar();
		$post_status_registrar->register();

		$taxonomy_registrar = new ActionScheduler_wpPostStore_TaxonomyRegistrar();
		$taxonomy_registrar->register();
	}
}
 
