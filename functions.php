<?php
	add_action('pre_get_posts','display_concerts');

	function display_concerts($query) {
		if($query->is_front_page() && $query->is_main_query()) {
			$query->set('post_type', array('concert'));

			//10 dernières années
			$query->set('date_query', array('year' => getdate()['year']-10, 'compare' => '>='));

			//le lieu n'est pas spécifié
			/*$query->set('meta_query', array(
				array(
					'key' => 'wpcf-lieu',
					'value' => false,
					'type' => BOOLEAN
				)
			));*/

			//qui possède une image à la une
			/*$query->set('meta_query', array(
				array(
					'key' => '_thumbnail_id',
					'compare' => 'EXISTS'
				)
			));*/

			$query->set('date_query', array(
					array(
						'year' => '2006',
						'compare' => '>='
					),
					array(
						'year' => '2008',
						'compare' => '<='
					),
					'relation' => 'AND'
			));
			

			return;
		}
	}

	function dashboard_widget_function() {
		$noLieu = new WP_Query();
		$noLieu->set('post_type', array('concert'));
		$noLieu->set('meta_query', array(
			'key' => 'wpcf-lieu',
			'value' => FALSE,
			'type' => BOOLEAN
		));
		$resultLieu = $noLieu->get_posts();
		echo 'Concerts sans lieu : ' . count($resultLieu) . '<br />';

		$noPays = new WP_Query();
		$noPays->set('post_type', array('action'));
		$noPays->set('meta_query', array(
			'key' => 'wpcf-pays',
			'value' => FALSE,
			'type' => BOOLEAN
		));
		$resultPays = $noPays->get_posts();
		echo 'Actions sans pays : ' . count($resultPays);
	}

	function add_dashboard_widgets() {
		wp_add_dashboard_widget('dashboard_widget', 'Actions et concerts sans localisation', 'dashboard_widget_function');
	}

	add_action('wp_dashboard_setup', 'add_dashboard_widgets');

	function geolocalize($post_id) {
		$type = array('action', 'concert');

		if(wp_is_post_revision($post_id)) {
			return;
		}

		$post = get_post($post_id);
		if(!in_array($post->post_type, $type))
			return;

		if($post->post_type === 'concert') $lieu = get_post_meta($post_id, 'wpcf-lieu', TRUE);
		else if($post->post_type === 'action') $lieu = get_the_terms($post_id, 'pays');


		if(empty($lieu)) {
			return;
		}
		
		$lat = get_post_meta($post_id, 'lat', TRUE);
		if(empty($lat)) {
			if($post->post_type === 'concert') {
				$address = $lieu . ', France';
			}
			else if($post->post_type === 'action') {
				$address = $lieu[0]->name;
			}

			$result = doGeolocation($address);

			if(false === $result) 
				return;
			try {
				$location = $result[0]['geometry']['location'];
				add_post_meta($post_id, 'lat', $location['lat']);
				add_post_meta($post_id, 'lng', $location['lng']);
			}
			catch(Exception $e) {
				return;
			}
		}
	}
	add_action('save_post', 'geolocalize');

	function doGeolocation($address) {
		$url = 'http://maps.google.com/maps/api/geocode/json?sensor=false&address=' . urlencode($address);

		if($json = file_get_contents($url)) {
			$data = json_decode($json, TRUE);
			if($data['status'] == 'OK') {
				return $data['results'];
			}
		}
		return FALSE;
	}

	function load_scripts() {
		if(! is_post_type_archive('concert') && ! is_post_type_archive('action')) 
			return;
		wp_register_script('leaflet-js', 'http://cdn.leafletjs.com/leaflet-0.7.1/leaflet.js');
		wp_enqueue_script('leaflet-js');

		wp_register_style('leaflet-css', 'http://cdn.leafletjs.com/leaflet-0.7.1/leaflet.css');
		wp_enqueue_style('leaflet-css');
	}
	add_action('wp_enqueue_scripts', 'load_scripts');

	function getPosWithLatLon($post_type = 'concert') {
		global $wpdb;
		$query = "
		SELECT ID, post_title, p1.meta_value as lat, p2.meta_value as lng
		FROM wp_posts, wp_postmeta as p1, wp_postmeta as p2
		WHERE wp_posts.post_type = '$post_type'
		AND p1.post_id = wp_posts.ID
		AND p2.post_id = wp_posts.ID
		AND p1.meta_key = 'lat'
		AND p2.meta_key = 'lng'
		";

		return $wpdb->get_results($query);
	}

	function getMarkerList($post_type = 'concert') {
		$results = getPosWithLatLon($post_type);
		$array = array();
		foreach($results as $result) {
			$array[] = "var marker_" . $result->ID . " = L.marker([" . $result->lat . ", " . $result->lng . "]).addTo(map);";
			$array[] = "var popup_" . $result->ID . " = L.popup().setContent('" . $result->post_title . "');";
			$array[] = "popup_" . $result->ID . ".post_id = " . $result->ID . ';';
			$array[] = "marker_" . $result->ID . ".bindPopup(popup_" . $result->ID . ");";
		}

		return implode(PHP_EOL, $array);
	}

	add_action('wp_ajax_popup_content', 'get_content');
	add_action('wp_ajax_nopriv_popup_content', 'get_content');

	function get_content() {
		if( !wp_verify_nonce($_REQUEST['nonce'], 'popup_content')) {
			exit("d'où vient cette requête ?");
		}
		else {
			$post_id = $_REQUEST['post_id'];
			$postQuery = new WP_Query();
			$postQuery->set('p', $post_id);
			$ajaxResult = $postQuery->get_posts();
			echo $ajaxResult[0]->post_title . $ajaxResult[0]->post_content;
		}
		die();
	}

?>