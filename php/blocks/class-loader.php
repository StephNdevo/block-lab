<?php
/**
 * Loader initiates the loading of new Gutenberg blocks for the Block_Lab plugin.
 *
 * @package Block_Lab
 */

namespace Block_Lab\Blocks;

use Block_Lab\Component_Abstract;

/**
 * Class Loader
 */
class Loader extends Component_Abstract {

	/**
	 * Asset paths and urls for blocks.
	 *
	 * @var array
	 */
	public $assets = [];

	/**
	 * JSON representing last loaded blocks.
	 *
	 * @var string
	 */
	public $blocks = '';

	/**
	 * Load the Loader.
	 *
	 * @return $this
	 */
	public function init() {
		$this->assets = [
			'path' => [
				'entry'        => $this->plugin->get_path( 'js/editor.blocks.js' ),
				'editor_style' => $this->plugin->get_path( 'css/blocks.editor.css' ),
			],
			'url'  => [
				'entry'        => $this->plugin->get_url( 'js/editor.blocks.js' ),
				'editor_style' => $this->plugin->get_url( 'css/blocks.editor.css' ),
			],
		];

		$this->retrieve_blocks();

		return $this;
	}

	/**
	 * Register all the hooks.
	 */
	public function register_hooks() {
		/**
		 * Gutenberg JS block loading.
		 */
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );

		/**
		 * PHP block loading.
		 */
		add_action( 'plugins_loaded', array( $this, 'dynamic_block_loader' ) );

		/**
		 * Filters the value output to the front-end template.
		 */
		add_action( 'block_lab_output_value', array( $this, 'get_output_value' ), 10, 3 );
	}


	/**
	 * Launch the blocks inside Gutenberg.
	 */
	public function editor_assets() {
		wp_enqueue_script(
			'block-lab-blocks',
			$this->assets['url']['entry'],
			array( 'wp-i18n', 'wp-element', 'wp-blocks', 'wp-components', 'wp-api-fetch' ),
			$this->plugin->get_version(),
			true
		);

		// Add dynamic Gutenberg blocks.
		wp_add_inline_script(
			'block-lab-blocks',
			'const blockLabBlocks = ' . $this->blocks,
			'before'
		);

		// Enqueue optional editor only styles.
		wp_enqueue_style(
			'block-lab-editor-css',
			$this->assets['url']['editor_style'],
			array(),
			$this->plugin->get_version()
		);
	}

	/**
	 * Loads dynamic blocks via render_callback for each block.
	 */
	public function dynamic_block_loader() {

		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Get blocks.
		$blocks = json_decode( $this->blocks, true );

		foreach ( $blocks as $block_name => $block ) {
			$attributes = $this->get_block_attributes( $block );

			// sanitize_title() allows underscores, but register_block_type doesn't.
			$block_name = str_replace( '_', '-', $block_name );

			// register_block_type doesn't allow slugs starting with a number.
			if ( is_numeric( $block_name[0] ) ) {
				$block_name = 'block-' . $block_name;
			}

			register_block_type(
				$block_name,
				array(
					'attributes'      => $attributes,
					// @see https://github.com/WordPress/gutenberg/issues/4671
					'render_callback' => function ( $attributes ) use ( $block ) {
						return $this->render_block_template( $block, $attributes );
					},
				)
			);
		}
	}

	/**
	 * Gets block attributes.
	 *
	 * @param array $block An array containing block data.
	 *
	 * @return array
	 */
	public function get_block_attributes( $block ) {
		$attributes = [];

		if ( ! isset( $block['fields'] ) ) {
			return $attributes;
		}

		foreach ( $block['fields'] as $field_name => $field ) {
			$attributes[ $field_name ] = [];

			if ( ! empty( $field['type'] ) ) {
				$attributes[ $field_name ]['type'] = $field['type'];
			} else {
				$attributes[ $field_name ]['type'] = 'string';
			}

			if ( ! empty( $field['default'] ) ) {
				$attributes[ $field_name ]['default'] = $field['default'];
			}

			if ( 'array' === $field['type'] ) {
				/**
				 * This is a workaround to allow empty array values. We unset the default value before registering the
				 * block so that the default isn't used to auto-correct empty arrays. This allows the default to be
				 * used only when creating the form.
				 */
				unset( $attributes[ $field_name ]['default'] );
				$attributes[ $field_name ]['items'] = array( 'type' => 'string' );
			}

			if ( ! empty( $field['source'] ) ) {
				$attributes[ $field_name ]['source'] = $field['source'];
			}

			if ( ! empty( $field['meta'] ) ) {
				$attributes[ $field_name ]['meta'] = $field['meta'];
			}

			if ( ! empty( $field['selector'] ) ) {
				$attributes[ $field_name ]['selector'] = $field['selector'];
			}

			if ( ! empty( $field['query'] ) ) {
				$attributes[ $field_name ]['query'] = $field['query'];
			}
		}

		return $attributes;
	}

	/**
	 * Renders the block provided a template is provided.
	 *
	 * @param array $block      The block to render.
	 * @param array $attributes Attributes to render.
	 *
	 * @return mixed
	 */
	public function render_block_template( $block, $attributes ) {
		global $block_lab_attributes, $block_lab_config;

		$type = 'block';

		// This is hacky, but the editor doesn't send the original request along.
		$context = filter_input( INPUT_GET, 'context', FILTER_SANITIZE_STRING );

		if ( 'edit' === $context ) {
			$type = array( 'preview', 'block' );
		}

		if ( 'edit' !== $context ) {
			/**
			 * The block has been added, but its values weren't saved (not even the defaults). This is a phenomenon
			 * unique to frontend output, as the editor fetches is attributes from the form fields themselves.
			 */
			$missing_schema_attributes = array_diff_key( $block['fields'], $attributes );
			foreach ( $missing_schema_attributes as $attribute_name => $schema ) {
				if ( isset( $schema['default'] ) ) {
					$attributes[ $attribute_name ] = $schema['default'];
				}
			}
		}

		$block_lab_attributes = $attributes;
		$block_lab_config     = $block;

		ob_start();
		block_lab_template_part( $block['name'], $type );
		$output = ob_get_clean();

		return $output;
	}

	/**
	 * Load all the published blocks and blocks/block.json files.
	 */
	public function retrieve_blocks() {
		$slug = 'block_lab';

		$this->blocks = '';
		$blocks       = [];

		// Retrieve blocks from blocks.json.
		// Reverse to preserve order of preference when using array_merge.
		$blocks_files = array_reverse( (array) block_lab_locate_template( 'blocks/blocks.json', '', false ) );
		foreach ( $blocks_files as $blocks_file ) {
			// This is expected to be on the local filesystem, so file_get_contents() is ok to use here.
			$json       = file_get_contents( $blocks_file ); // @codingStandardsIgnoreLine
			$block_data = json_decode( $json, true );

			// Merge if no json_decode error occurred.
			if ( json_last_error() == JSON_ERROR_NONE ) { // Loose comparison okay.
				$blocks = array_merge( $blocks, $block_data );
			}
		}

		$block_posts = new \WP_Query(
			[
				'post_type'      => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => - 1,
			]
		);

		if ( 0 < $block_posts->post_count ) {
			/** The WordPress Post object. @var \WP_Post $post */
			foreach ( $block_posts->posts as $post ) {
				$block_data = json_decode( $post->post_content, true );

				// Merge if no json_decode error occurred.
				if ( json_last_error() == JSON_ERROR_NONE ) { // Loose comparison okay.
					$blocks = array_merge( $blocks, $block_data );
				}
			}
		}

		$this->blocks = wp_json_encode( $blocks );
	}

	/**
	 * Gets the value to be made available or echoed on the front-end template.
	 *
	 * Gets the value based on the control type.
	 * For example, a 'user' control can return a WP_User, a string, or false.
	 * The $echo parameter is whether the value will be echoed on the front-end template,
	 * or simply made available.
	 *
	 * @param mixed  $value The output value.
	 * @param string $control The type of the control, like 'user'.
	 * @param bool   $echo Whether or not this value will be echoed.
	 * @return mixed $value The filtered output value.
	 */
	public function get_output_value( $value, $control, $echo ) {
		switch ( $control ) {
			case 'user':
				$wp_user = get_user_by( 'login', $value );
				if ( $echo ) {
					return $wp_user ? $wp_user->get( 'display_name' ) : '';
				} else {
					return $wp_user ? $wp_user : false;
				}
				break;
		}

		return $value;
	}
}
