<?php
/**
 * Class Stories_Base_Controller
 *
 * @package   Google\Web_Stories
 * @copyright 2020 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://github.com/google/web-stories-wp
 */

/**
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Web_Stories\REST_API;

use Google\Web_Stories\KSES;
use stdClass;
use WP_Error;
use WP_Post;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Stories_Base_Controller class.
 *
 * Override the WP_REST_Posts_Controller class to add `post_content_filtered` to REST request.
 */
class Stories_Base_Controller extends WP_REST_Posts_Controller {
	/**
	 * Prepares a single template for create or update. Add post_content_filtered field to save/insert.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return stdClass|WP_Error Post object or WP_Error.
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared_template = parent::prepare_item_for_database( $request );

		if ( is_wp_error( $prepared_template ) ) {
			return $prepared_template;
		}
		// Ensure that content and story_data are updated together.
		if (
			( ! empty( $request['story_data'] ) && empty( $request['content'] ) ) ||
			( ! empty( $request['content'] ) && empty( $request['story_data'] ) )
		) {
			return new WP_Error( 'rest_empty_content', __( 'content and story_data should always be updated together.', 'web-stories' ), [ 'status' => 412 ] );
		}

		// If the request is updating the content as well, let's make sure the JSON representation of the story is saved, too.
		if ( isset( $request['story_data'] ) ) {
			$prepared_template->post_content_filtered = wp_json_encode( $request['story_data'] );
		}

		return $prepared_template;
	}

	/**
	 * Prepares a single template output for response.
	 *
	 * Adds post_content_filtered field to output.
	 *
	 * @param WP_Post         $post Post object.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $post, $request ) {
		$response = parent::prepare_item_for_response( $post, $request );
		$fields   = $this->get_fields_for_response( $request );
		$data     = $response->get_data();
		$schema   = $this->get_item_schema();

		if ( in_array( 'story_data', $fields, true ) ) {
			$post_story_data    = json_decode( $post->post_content_filtered, true );
			$data['story_data'] = rest_sanitize_value_from_schema( $post_story_data, $schema['properties']['story_data'] );
		}

		if ( in_array( 'featured_media_url', $fields, true ) ) {
			$image                      = get_the_post_thumbnail_url( $post, 'medium' );
			$data['featured_media_url'] = ! empty( $image ) ? $image : $schema['properties']['featured_media_url']['default'];
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->filter_response_by_context( $data, $context );
		$links   = $response->get_links();

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );
		foreach ( $links as $rel => $rel_links ) {
			foreach ( $rel_links as $link ) {
				$response->add_link( $rel, $link['href'], $link['attributes'] );
			}
		}

		/* This filter is documented in wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php */
		return apply_filters( "rest_prepare_{$this->post_type}", $response, $post, $request );
	}

	/**
	 * Creates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$kses = new KSES();
		$kses->init();
		$response = parent::create_item( $request );
		$kses->remove_filters();
		return $response;
	}

	/**
	 * Updates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$kses = new KSES();
		$kses->init();
		$response = parent::update_item( $request );
		$kses->remove_filters();
		return $response;
	}

	/**
	 * Retrieves the story's schema, conforming to JSON Schema.
	 *
	 * @return array Item schema as an array.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = parent::get_item_schema();

		$schema['properties']['story_data'] = [
			'description' => __( 'Story data stored as a JSON object. Stored in post_content_filtered field.', 'web-stories' ),
			'type'        => 'object',
			'context'     => [ 'edit' ],
			'default'     => [],
		];

		$schema['properties']['featured_media_url'] = [
			'description' => __( 'URL for the story\'s poster image (portrait)', 'web-stories' ),
			'type'        => 'string',
			'format'      => 'uri',
			'context'     => [ 'view', 'edit', 'embed' ],
			'readonly'    => true,
			'default'     => '',
		];

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}
}
