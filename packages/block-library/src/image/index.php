<?php
/**
 * core/image block callback.
 */

// TODO: TBD
function _gutenberg_prime_post_caches( $content ) {
	// Need to find all image blocks and get the attachment IDs from them BEFORE the parser is run
	// (so we can prime the cache for getting the image attachment meta)...
	if ( preg_match_all( '/^<!-- wp:image {.*"id":(\d+),.*} -->$/m', $content, $matches ) ) {
		$attachment_ids = $matches[1];

		/*
		 * Warm the object cache with post and meta information for all found
		 * images to avoid making individual database calls.
		 */
		_prime_post_caches( $attachment_ids, false, true );
	}

	return $content;
}

// Run before blocks are parsed.
add_filter( 'the_content', '_gutenberg_prime_post_caches', 5 );

/**
 * Get the expected block width from the global $block_width array.
 *
 * The global $block_width array is expectd to be set by the theme for each block container.
 * It should contain three values: default, wide and full, in pixels.
 * - The `default` value should be the expected block width (similarly to $content_width).
 * - The `wide` value is optional and is used when the block alignment is set to `wide`.
 * - Similarly the `full` value is optional and used then the alignment is set to `full`.
 * If `wide` and `full` are not set, the `default` value is used instead.
 *
 * Example:
 *     $block_width = array(
 *         'default' => 640,
 *         'wide'    => 720,
 *         'full'    => 900,
 *     );
 *
 * In addition the $block_width array should be set contextually for each block container.
 * For example: in the main content column the `default` width will be something like 640(px),
 * but for a sidebar it would be something like 250.
 *
 * @since 5.0.0
 *
 * @return array Normalized array of expected block width with three elements: `default`, `wide`, and `full`.
 */
function gutenberg_get_block_width() {
	global $content_width, $block_width;

	// Get the width from the $block_width global array
	// or fall back to using $content_width.
	if ( empty( $block_width ) || ! is_array( $block_width ) || empty( $block_width['default'] ) ) {
		if ( empty( $content_width ) ) {
			$default_width = 1024; // Fall back to the "large" image file width.
		} else {
			$default_width = $content_width;
		}

		$width = array(
			'default' => $default_width,
			'wide' => $default_width,
			'full' => $default_width,
		);
	} else {
		$width = $block_width;

		if ( empty( $width['wide'] ) ) {
			$width['wide'] = $width['default'];
		}

		if ( empty( $width['full'] ) ) {
			$width['full'] = $width['default'];
		}
	}

	return $width;
}

/**
 * Constrain the requested image width to the width of the (largest) image
 * to avoid upscaling.
 *
 * @access private
 * @since 5.0.0
 *
 * @return int The constrained image width.
 */
function _gutenberg_constrain_image_width( $image_width, $image_meta ) {
	if ( $image_width <= $image_meta['width'] ) {
		return (int) $image_width;
	}

	return (int) $image_meta['width'];
}

/**
 * Calculates the image width and height based on $block_witdh and the `scale` block attribute.
 *
 * @since 5.0.0
 *
 * @param array $block_attributes The block attributes.
 * @param array $image_meta The image attachment meta data.
 * @return array An array of the image width and height, in that order.
 */
function gutenberg_get_image_width_height( $block_attributes, $image_meta ) {
	$block_witdh = gutenberg_get_block_width();

	if ( $block_attributes['align'] === 'full' ) {
		$image_width = _gutenberg_constrain_image_width( $block_witdh['full'], $image_meta );
	} elseif ( $block_attributes['align'] === 'wide' ) {
		$image_width = _gutenberg_constrain_image_width( $block_witdh['wide'], $image_meta );
	} else {
		$image_width = $block_witdh['default'];

		// Scale the image if it was resized in the editor.
		if ( ! empty( $block_attributes['scale'] ) && $block_attributes['scale'] < 1 ) {
			$image_width = round( $image_width * $block_attributes['scale'] );
		}

		// Check if we have large enough image to display,
		// or we need to display the full imagre and reduce the width to match.
		$image_width = _gutenberg_constrain_image_width( $image_width, $image_meta );
	}

	// Calculate the height.
	$image_size = wp_constrain_dimensions( $image_meta['width'], $image_meta['height'], $image_width );
	$image_height = $image_size[1];

	// TODO: filter?

	return array( $image_width, $image_height );
}

function gutenberg_render_block_core_image( $block_attributes = array(), $html = '' ) {
	// Something's wrong. Perhaps an old post?
	if ( empty( $html ) || empty( $block_attributes ) || empty( $block_attributes['url'] ) ) {
		return $html;
	}

	$attachment_id = (int) $block_attributes['id'];
	$image_src = esc_url_raw( $block_attributes['url'] );
	$image_meta = wp_get_attachment_metadata( $attachment_id );
	$srcset = '';
	$sizes = '';

	// Something's wrong. Broken or very old image meta?
	if ( empty( $image_meta['height'] ) || empty( $image_meta['width'] ) ) {
		return $html;
	}

	$image_dimensions = gutenberg_get_image_width_height( $block_attributes, $image_meta );

	if ( empty( $block_attributes['srcSet'] ) ) {
		$srcset = wp_calculate_image_srcset( $image_dimensions, $image_src, $image_meta, $attachment_id );
	} else {
		// TODO: Perhaps (cheaply) check if we need to regenerate the srcset?
		// We already have the image meta, can perhaps look in there?
		$sources = array();

		// Filter the srcset the same way as in `wp_calculate_image_srcset()`.
		$srcset_array = explode( ', ', $block_attributes['srcSet'] );

		foreach ( $srcset_array as $image_candidate ) {
			$image_candidate_array = explode( ' ', $image_candidate, 2 );

			if ( count( $image_candidate_array ) === 2 && preg_match( '/(\d+)(\w)/', $image_candidate_array[1], $values ) ) {
				$width = (int) $values[1];

				$sources[ $width ] = array(
					'url'        => $image_candidate_array[0],
					'value'      => $width,
					'descriptor' => $values[2],
				);
			}
		}

		/** This filter is documented in wp-includes/media.php */
		$sources = apply_filters( 'wp_calculate_image_srcset', $sources, $image_dimensions, $image_src, $image_meta, $attachment_id );

		if ( is_array( $sources ) && count( $sources ) > 1 ) {
			foreach ( $sources as $source ) {
				$url = esc_url_raw( $source['url'] );
				$value = (int) $source['value'];
				$descriptor = '';

				// The descriptor can only be `w` or `x`.
				// See https://html.spec.whatwg.org/multipage/images.html#srcset-attributes.
				if ( $source['descriptor'] === 'w' || $source['descriptor'] === 'x' ) {
					$descriptor = $source['descriptor'];
				}

				$srcset .= $url . ' ' . $value . $descriptor . ', ';
			}

			$srcset = rtrim( $srcset, ', ' );
		}

	}

	if ( ! empty( $srcset ) ) {
		$sizes = wp_calculate_image_sizes( $image_dimensions, $image_src, $image_meta, $attachment_id );
	}

	$image_attributes = array(
		'src' => $image_src,
		'alt' => empty( $block_attributes['alt'] ) ? '' : esc_attr( $block_attributes['alt'] ),
		'width' => (int) $image_dimensions[0],
		'height' => (int) $image_dimensions[1],
	);

	if ( $srcset && $sizes ) {
		// Sanitized above.
		$image_attributes['srcset'] = $srcset;
		// Regeneraed, as the width may have changed.
		$image_attributes['sizes'] = $sizes;
	}

	$attr = '';
	foreach ( $image_attributes as $name => $value ) {
		$attr .= sprintf( ' %s="%s"', $name, $value );
	}

	$image_tag = '<img' . $attr . '/ >';

	// Replace the img tag.
	$html = preg_replace( '/<img [^>]+>/', $image_tag, $html );

	return $html;
}

function register_block_core_image() {
	register_block_type(
		'core/image',
		array(
			'attributes' => array(
				'className' => array(
					'type' => 'string', // Any custom class name(s) the user has entered.
				),
				'url' => array(
					'type' => 'string', // The img src.
				),
				'alt' => array(
					'type' => 'string',
				),
				'id' => array(
					'type' => 'number',
				),
				'align' => array(
					'type' => 'string',
					'enum' => array( 'center', 'left', 'right', 'wide', 'full', '' ),
				),
				'scale' => array(
					'type' => 'number',
				),
				'srcSet' => array(
					'type' => 'string',
				),
				'sizes' => array(
					'type' => 'string',
				),
			),
			'render_callback' => 'gutenberg_render_block_core_image',
		)
	);
}

add_action( 'init', 'register_block_core_image' );
