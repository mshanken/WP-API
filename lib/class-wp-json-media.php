<?php

class WP_JSON_Media extends WP_JSON_Posts {
	/**
	 * Retrieve pages
	 *
	 * Overrides the $type to set to 'attachment', then passes through to the post
	 * endpoints.
	 *
	 * @see WP_JSON_Posts::getPosts()
	 */
	public function getPosts( $filter = array(), $context = 'view', $type = 'attachment', $page = 1 ) {
		if ( $type !== 'attachment' )
			return new WP_Error( 'json_post_invalid_type', __( 'Invalid post type' ), array( 'status' => 400 ) );

		return parent::getPosts( $filter, $context, 'attachment', $page );
	}

	/**
	 * Retrieve a attachment
	 *
	 * @see WP_JSON_Posts::getPost()
	 */
	public function getPost( $id, $context = 'view' ) {
		global $wp_json_server;
		$id = (int) $id;

		if ( empty( $id ) )
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );

		$post = get_post( $id, ARRAY_A );

		if ( $post['post_type'] !== 'attachment' )
			return new WP_Error( 'json_post_invalid_type', __( 'Invalid post type' ), array( 'status' => 400 ) );

		return parent::getPost( $id, $context );
	}

	/**
	 * Get attachment-specific data
	 *
	 * @param array $post
	 * @return array
	 */
	public function prepare_post( $post, $fields, $context = 'single' ) {
		$data = parent::prepare_post( $post, $fields, $context );

		// $thumbnail_size = current_theme_supports( 'post-thumbnail' ) ? 'post-thumbnail' : 'thumbnail';
		$data['source'] = wp_get_attachment_url( $post['ID'] );
		$data['is_image'] = wp_attachment_is_image( $post['ID'] );

		$data['attachment_meta'] = wp_get_attachment_metadata( $post['ID'] );

		if ( ! empty( $data['attachment_meta']['sizes'] ) ) {
			$img_url_basename = wp_basename( $data['source'] );

			foreach ($data['attachment_meta']['sizes'] as $size => &$size_data) {
				// Use the same method image_downsize() does
				$size_data['url'] = str_replace( $img_url_basename, $size_data['file'], $data['source'] );
			}
		}

		return $data;
	}

	/**
	 * Edit a attachment
	 *
	 * @see WP_JSON_Posts::editPost()
	 */
	public function editPost( $id, $data, $_headers = array() ) {
		$id = (int) $id;
		if ( empty( $id ) )
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );

		$post = get_post( $id, ARRAY_A );

		if ( empty( $post['ID'] ) )
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );

		if ( $post['post_type'] !== 'attachment' )
			return new WP_Error( 'json_post_invalid_type', __( 'Invalid post type' ), array( 'status' => 400 ) );

		return parent::editPost( $id, $data, $_headers );
	}

	/**
	 * Delete a attachment
	 *
	 * @see WP_JSON_Posts::deletePost()
	 */
	public function deletePost( $id, $force = false ) {
		$id = (int) $id;

		if ( empty( $id ) )
			return new WP_Error( 'json_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) );

		$post = get_post( $id, ARRAY_A );

		if ( $post['post_type'] !== 'attachment' )
			return new WP_Error( 'json_post_invalid_type', __( 'Invalid post type' ), array( 'status' => 400 ) );

		return parent::deletePost( $id, $force );
	}

	public function uploadAttachment( $_files, $_headers ) {
		global $wp_json_server;

		// Get the file via $_FILES or raw data
		if ( empty( $_files ) ) {
			$file = $this->uploadFromData( $_files, $_headers );
		}
		else {
			$file = $this->uploadFromFile( $_files, $_headers );
		}

		if ( is_wp_error( $file ) )
			return $file;

		$name = basename( $file['file'] );
		$name_parts = pathinfo( $name );
		$name = trim( substr( $name, 0, -(1 + strlen($name_parts['extension'])) ) );

		$url = $file['url'];
		$type = $file['type'];
		$file = $file['file'];
		$title = $name;
		$content = '';

		// use image exif/iptc data for title and caption defaults if possible
		if ( $image_meta = @wp_read_image_metadata($file) ) {
			if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) )
				$title = $image_meta['title'];
			if ( trim( $image_meta['caption'] ) )
				$content = $image_meta['caption'];
		}

		// Construct the attachment array
		$post_data = array();
		$attachment = array(
			'post_mime_type' => $type,
			'guid' => $url,
			// 'post_parent' => $post_id,
			'post_title' => $title,
			'post_content' => $content,
		);

		// This should never be set as it would then overwrite an existing attachment.
		if ( isset( $attachment['ID'] ) )
			unset( $attachment['ID'] );

		// Save the data
		$id = wp_insert_attachment($attachment, $file /*, $post_id */);
		if ( !is_wp_error($id) ) {
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		}

		return $id;
	}

	protected function uploadFromData( $_files, $_headers ) {
		global $wp_json_server;
		$data = $wp_json_server->get_raw_data();

		if ( empty( $data ) ) {
			return new WP_Error( 'json_upload_no_data', __( 'No data supplied' ), array( 'status' => 400 ) );
		}

		if ( empty( $_headers['CONTENT_TYPE'] ) ) {
			return new WP_Error( 'json_upload_no_type', __( 'No Content-Type supplied' ), array( 'status' => 400 ) );
		}

		if ( empty( $_headers['CONTENT_DISPOSITION'] ) ) {
			return new WP_Error( 'json_upload_no_disposition', __( 'No Content-Disposition supplied' ), array( 'status' => 400 ) );
		}

		// Get the filename
		$disposition_parts = explode(';', $_headers['CONTENT_DISPOSITION']);
		$filename = null;
		foreach ($disposition_parts as $part) {
			$part = trim($part);

			if (strpos($part, 'filename') !== 0)
				continue;

			$filenameparts = explode('=', $part);
			$filename = trim($filenameparts[1]);
		}

		if ( empty( $filename ) ) {
			return new WP_Error( 'json_upload_invalid_disposition', __( 'Invalid Content-Disposition supplied' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $_headers['CONTENT_MD5'] ) ) {
			$expected = trim( $_headers['CONTENT_MD5'] );
			$actual = md5( $data );
			if ( $expected !== $actual ) {
				return new WP_Error( 'json_upload_hash_mismatch', __( 'Content hash did not match expected' ), array( 'status' => 412 ) );
			}
		}

		// Get the content-type
		$type = $_headers['CONTENT_TYPE'];

		// Save the file
		$tmpfname = wp_tempnam( $filename );

		$fp = fopen( $tmpfname, 'w+' );
		if ( ! $fp ) {
			return new WP_Error( 'json_upload_file_error', __( 'Could not open file handle' ), array( 'status' => 500 ) );
		}

		fwrite( $fp, $data );
		fclose( $fp );

		// Now, sideload it in
		$file_data = array(
			'error' => null,
			'tmp_name' => $tmpfname,
			'name' => $filename,
			'type' => $type,
		);
		$overrides = array(
			'test_form' => false,
		);
		$sideloaded = wp_handle_sideload( $file_data, $overrides );

		if ( isset( $sideloaded['error'] ) ) {
			@unlink( $tmpfname );
			return new WP_Error( 'json_upload_sideload_error', $sideloaded['error'], array( 'status' => 500 ) );
		}

		return $sideloaded;
	}

	protected function uploadFromFile( $_files, $_headers ) {
		if ( empty( $_files['file'] ) )
			return new WP_Error( 'json_upload_no_data', __( 'No data supplied' ), array( 'status' => 400 ) );

		// Verify hash, if given
		if ( ! empty( $_headers['CONTENT_MD5'] ) ) {
			$expected = trim( $_headers['CONTENT_MD5'] );
			$actual = md5_file( $_files['file']['tmp_name'] );
			if ( $expected !== $actual ) {
				return new WP_Error( 'json_upload_hash_mismatch', __( 'Content hash did not match expected' ), array( 'status' => 412 ) );
			}
		}

		// Pass off to WP to handle the actual upload
		$overrides = array(
			'test_form' => false,
		);
		$file = wp_handle_upload( $_files['file'], $overrides );

		if ( isset($file['error']) )
			return new WP_Error( 'json_upload_unknown_error', $file['error'], array( 'status' => 500 ) );

		return $file;
	}
}