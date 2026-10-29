<?php
/**
 * Categories screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Capabilities;
use EasyQ\Categories\Categories;
use EasyQ\Categories\Category;
use EasyQ\Frontend\URLs;
use EasyQ\REST\Post_Input;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, adds, changes, orders and deletes categories, and moves questions that
 * lack a category or subcategory, like the Question2Answer category admin page.
 *
 * Laid out like the core category screen: the form to add a category next to the
 * list, and a separate page to edit a category.
 *
 * @since 0.1.0
 */
final class Categories_Screen {

	/**
	 * Page slug of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-categories';

	/**
	 * Nonce action of the forms.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE = 'easyq-categories';

	/**
	 * Errors of the last submitted form, keyed by field.
	 *
	 * @since 0.1.0
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $errors = null;

	/**
	 * Values of the last submitted form, shown again with its errors.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private array $input = array();

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// After the dashboard, which creates the easyQ menu.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
	}

	/**
	 * Adds the screen to the easyQ menu if the site uses categories.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		if ( ! Post_Input::uses_categories() ) {
			return;
		}

		$hook = add_submenu_page( Dashboard_Screen::PAGE, __( 'Categories', 'easyq' ), __( 'Categories', 'easyq' ), Capabilities::MANAGE, self::PAGE, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Returns the address of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Returns the address of the page that edits a category.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Category ID.
	 * @return string
	 */
	public static function edit_url( int $id ): string {
		return self::url(
			array(
				'action'   => 'edit',
				'category' => $id,
			)
		);
	}

	/**
	 * Handles a submitted form before the screen is shown; redirects after a change.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		if ( ! isset( $_POST['easyq_action'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$redirect = $this->handle( wp_unslash( $_POST ) );
		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Carries out a submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $post Unslashed form data; the nonce has been checked.
	 * @return string|null Address to go to after a change, or null to show the form again with its errors.
	 */
	public function handle( array $post ): ?string {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage categories.', 'easyq' ), 403 );
		}

		$categories = Services::categories();
		foreach ( array( 'easyq_action', 'title', 'slug', 'description', 'parent', 'position', 'category', 'reassign' ) as $key ) {
			$this->input[ $key ] = isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
		}

		$id          = self::id( $this->input['category'] );
		$title       = sanitize_text_field( $this->input['title'] );
		$slug        = sanitize_title( $this->input['slug'] );
		$description = sanitize_textarea_field( $this->input['description'] );
		$parent_id   = self::id( $this->input['parent'] );
		$position    = '' === $this->input['position'] ? null : max( 1, (int) $this->input['position'] );

		switch ( $this->input['easyq_action'] ) {
			case 'add':
				$result = $categories->create( $parent_id, $title, $slug, $description );
				if ( is_int( $result ) ) {
					return self::url(
						array(
							'message' => 'added',
							'parent'  => $parent_id ?? 0,
						)
					);
				}
				break;

			case 'save':
				$category = null === $id ? null : $categories->find( $id );
				if ( null === $category ) {
					return self::url( array( 'message' => 'missing' ) );
				}

				// The position is chosen among the current siblings; a moved category goes to the end.
				$moves  = $parent_id !== $category->parent_id;
				$result = $categories->save( $category->id, $title, $slug, $description, $parent_id, $moves ? null : $position );
				if ( true === $result ) {
					return self::url(
						array(
							'action'   => 'edit',
							'category' => $category->id,
							'message'  => 'saved',
						)
					);
				}
				break;

			case 'delete':
				$category = null === $id ? null : $categories->find( $id );
				if ( null === $category ) {
					return self::url( array( 'message' => 'missing' ) );
				}

				$result = $categories->delete( $category->id, self::id( $this->input['reassign'] ) );
				if ( true === $result ) {
					return self::url( array( 'message' => 'deleted' ) );
				}
				break;

			case 'reassign':
				$result = $categories->reassign( $id, self::id( $this->input['reassign'] ) );
				if ( true === $result ) {
					return null === $id
						? self::url( array( 'message' => 'moved' ) )
						: self::url(
							array(
								'action'   => 'edit',
								'category' => $id,
								'message'  => 'moved',
							)
						);
				}
				break;

			default:
				$result = new WP_Error( 'action', __( 'Unknown action.', 'easyq' ) );
		}

		$this->errors = $result instanceof WP_Error ? $result : null;

		return null;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$categories = Services::categories();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only selects what to show; changes are posted with a nonce.
		$action  = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id      = isset( $_GET['category'] ) && is_string( $_GET['category'] ) ? self::id( sanitize_text_field( wp_unslash( $_GET['category'] ) ) ) : null;
		$parent  = isset( $_GET['parent'] ) && is_string( $_GET['parent'] ) ? self::id( sanitize_text_field( wp_unslash( $_GET['parent'] ) ) ) : null;
		$message = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$category = null === $id ? null : $categories->find( $id );

		echo '<div class="wrap">';

		if ( 'edit' === $action && null !== $category ) {
			$this->render_edit( $categories, $category, $message );
		} elseif ( 'reassign' === $action && ( null === $id || null !== $category ) ) {
			$this->render_reassign( $categories, $category );
		} else {
			$this->render_list( $categories, $parent, $message );
		}

		echo '</div>';
	}

	/**
	 * Renders the list with the form to add a category.
	 *
	 * @since 0.1.0
	 *
	 * @param Categories $categories Category service.
	 * @param int|null   $parent_id  Preselected parent of a new category.
	 * @param string     $message    Message key from the last change.
	 */
	private function render_list( Categories $categories, ?int $parent_id, string $message ): void {
		printf( '<h1>%s</h1>', esc_html__( 'Categories', 'easyq' ) );
		$this->render_notices( $message );

		$missing = Options::get_bool( 'posting', 'allow_no_category' ) || array() === $categories->all() ? 0 : $categories->count_direct_questions( null );
		if ( $missing > 0 ) {
			$this->render_warning(
				/* translators: %s: number of questions. */
				sprintf( _n( '%s question has no category.', '%s questions have no category.', $missing, 'easyq' ), number_format_i18n( $missing ) ),
				self::url(
					array(
						'action'   => 'reassign',
						'category' => 0,
					)
				),
				__( 'Move them to a category', 'easyq' )
			);
		}

		$adding    = 'add' === ( $this->input['easyq_action'] ?? '' );
		$parent_id = $adding ? self::id( $this->input['parent'] ) : $parent_id;

		echo '<div id="col-container" class="wp-clearfix"><div id="col-left"><div class="col-wrap"><div class="form-wrap">';
		printf( '<h2 id="easyq-category-add">%s</h2>', esc_html__( 'Add Category', 'easyq' ) );
		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="add">';

		$this->render_input( 'title', __( 'Name', 'easyq' ), $adding ? $this->input['title'] : '', true );
		$this->render_input( 'slug', __( 'Slug', 'easyq' ), $adding ? $this->input['slug'] : '', false, __( 'The address part of the category page. Derived from the name if left empty.', 'easyq' ) );

		echo '<div class="form-field">';
		$this->render_label( 'parent', __( 'Parent Category', 'easyq' ) );
		$this->render_category_select( 'parent', $categories->possible_parents( null ), $parent_id, __( 'None (top level)', 'easyq' ) );
		$this->render_error( 'parent' );
		printf( '<p>%s</p>', esc_html( sprintf( /* translators: %d: maximum depth. */ __( 'Categories can be nested up to %d levels deep.', 'easyq' ), Categories::MAX_DEPTH ) ) );
		echo '</div>';

		$this->render_textarea( 'description', __( 'Description', 'easyq' ), $adding ? $this->input['description'] : '' );
		submit_button( __( 'Add Category', 'easyq' ) );
		echo '</form></div></div></div>';

		echo '<div id="col-right"><div class="col-wrap">';
		$table = new Categories_List_Table( $categories );
		$table->prepare_items();
		$table->display();
		echo '</div></div></div>';
	}

	/**
	 * Renders the form to edit a category, and the form to delete it.
	 *
	 * @since 0.1.0
	 *
	 * @param Categories $categories Category service.
	 * @param Category   $category   Category.
	 * @param string     $message    Message key from the last change.
	 */
	private function render_edit( Categories $categories, Category $category, string $message ): void {
		$saving   = 'save' === ( $this->input['easyq_action'] ?? '' );
		$children = $categories->children( $category->id );
		$depth    = count( $categories->path( $category->id ) );

		printf( '<h1>%s</h1>', esc_html__( 'Edit Category', 'easyq' ) );
		$this->render_notices( $message );

		if ( array() !== $children && ! Options::get_bool( 'posting', 'allow_no_sub_category' ) ) {
			$direct = $categories->count_direct_questions( $category->id );
			if ( $direct > 0 ) {
				$this->render_warning(
					/* translators: %s: number of questions. */
					sprintf( _n( '%s question in this category has no subcategory.', '%s questions in this category have no subcategory.', $direct, 'easyq' ), number_format_i18n( $direct ) ),
					self::url(
						array(
							'action'   => 'reassign',
							'category' => $category->id,
						)
					),
					__( 'Move them to a subcategory', 'easyq' )
				);
			}
		}

		printf( '<p><a href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( '← Back to Categories', 'easyq' ) );

		echo '<form method="post" action="' . esc_url( self::edit_url( $category->id ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="save">';
		printf( '<input type="hidden" name="category" value="%d">', (int) $category->id );
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_row( 'title', __( 'Name', 'easyq' ), fn () => $this->render_text( 'title', $saving ? $this->input['title'] : $category->title, true ) );
		$this->render_row(
			'slug',
			__( 'Slug', 'easyq' ),
			fn () => $this->render_text( 'slug', $saving ? $this->input['slug'] : $category->slug, false, __( 'The address part of the category page. Derived from the name if left empty.', 'easyq' ) )
		);
		$this->render_row(
			'parent',
			__( 'Parent Category', 'easyq' ),
			function () use ( $categories, $category, $saving ): void {
				$this->render_category_select( 'parent', $categories->possible_parents( $category->id ), $saving ? self::id( $this->input['parent'] ) : $category->parent_id, __( 'None (top level)', 'easyq' ), 'easyq-category-parent-description' );
				printf(
					'<p class="description" id="easyq-category-parent-description">%s</p>',
					esc_html( sprintf( /* translators: %d: maximum depth. */ __( 'Categories can be nested up to %d levels deep, so some categories are not offered. A moved category goes to the end of its new siblings.', 'easyq' ), Categories::MAX_DEPTH ) )
				);
			}
		);
		$this->render_row(
			'position',
			__( 'Position', 'easyq' ),
			function () use ( $categories, $category ): void {
				$this->render_position_select( $categories, $category );
			}
		);
		$this->render_row(
			'description',
			__( 'Description', 'easyq' ),
			function () use ( $category, $saving ): void {
				printf(
					'<textarea class="large-text" rows="3" id="easyq-category-description" name="description"%s>%s</textarea>',
					$this->error_attributes( 'description' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the method.
					esc_textarea( $saving ? $this->input['description'] : $category->description )
				);
				$this->render_error( 'description' );
			}
		);

		echo '<tr><th scope="row">' . esc_html__( 'Questions', 'easyq' ) . '</th><td>';
		printf(
			'<a href="%s">%s</a>',
			esc_url( URLs::category( $category ) ),
			/* translators: %s: number of questions. */
			esc_html( sprintf( _n( '%s question, including subcategories', '%s questions, including subcategories', $category->question_count, 'easyq' ), number_format_i18n( $category->question_count ) ) )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Subcategories', 'easyq' ) . '</th><td>';
		if ( $depth >= Categories::MAX_DEPTH ) {
			echo esc_html( sprintf( /* translators: %d: maximum depth. */ __( 'This category cannot have subcategories because it is already %d levels down.', 'easyq' ), Categories::MAX_DEPTH ) );
		} else {
			$links = array_map(
				static fn ( Category $child ): string => sprintf( '<a href="%s">%s</a> (%s)', esc_url( self::edit_url( $child->id ) ), esc_html( $child->title ), esc_html( number_format_i18n( $child->question_count ) ) ),
				$children
			);
			echo array() === $links ? esc_html__( 'None', 'easyq' ) : implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			printf( ' – <a href="%s">%s</a>', esc_url( self::url( array( 'parent' => $category->id ) ) . '#easyq-category-add' ), esc_html__( 'Add subcategory', 'easyq' ) );
		}
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Update', 'easyq' ) );
		echo '</form>';

		printf( '<h2>%s</h2>', esc_html__( 'Delete Category', 'easyq' ) );
		if ( array() !== $children ) {
			printf( '<p>%s</p>', esc_html__( 'This category cannot be deleted because it has subcategories.', 'easyq' ) );

			return;
		}

		echo '<form method="post" action="' . esc_url( self::edit_url( $category->id ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="delete">';
		printf( '<input type="hidden" name="category" value="%d">', (int) $category->id );
		echo '<p>';
		$this->render_label( 'reassign', __( 'Move its questions to', 'easyq' ) );
		echo ' ';
		$others = array_values( array_filter( $categories->tree(), static fn ( array $item ): bool => $item[0]->id !== $category->id ) );
		$this->render_category_select( 'reassign', $others, $category->parent_id, __( 'No category', 'easyq' ) );
		$this->render_error( 'reassign' );
		echo '</p>';
		submit_button( __( 'Delete Category', 'easyq' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Renders the form that moves questions without a category, or without a
	 * subcategory, to another category.
	 *
	 * @since 0.1.0
	 *
	 * @param Categories    $categories Category service.
	 * @param Category|null $category   Category whose questions lack a subcategory, or null for questions without a category.
	 */
	private function render_reassign( Categories $categories, ?Category $category ): void {
		printf( '<h1>%s</h1>', esc_html__( 'Move Questions', 'easyq' ) );
		$this->render_notices( '' );

		$count = $categories->count_direct_questions( $category?->id );
		if ( null === $category ) {
			/* translators: %s: number of questions. */
			$intro   = sprintf( _n( '%s question has no category.', '%s questions have no category.', $count, 'easyq' ), number_format_i18n( $count ) );
			$label   = __( 'Move questions without a category to', 'easyq' );
			$targets = $categories->tree();
			$back    = self::url();
		} else {
			/* translators: 1: number of questions, 2: category name. */
			$intro = sprintf( _n( '%1$s question in “%2$s” has no subcategory.', '%1$s questions in “%2$s” have no subcategory.', $count, 'easyq' ), number_format_i18n( $count ), $category->title );
			/* translators: %s: category name. */
			$label   = sprintf( __( 'Move questions in “%s” without a subcategory to', 'easyq' ), $category->title );
			$targets = $categories->tree( $category->id, count( $categories->path( $category->id ) ) );
			$back    = self::edit_url( $category->id );
		}

		printf( '<p>%s</p>', esc_html( $intro ) );
		echo '<form method="post" action="' . esc_url(
			self::url(
				array(
					'action'   => 'reassign',
					'category' => $category->id ?? 0,
				)
			)
		) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="easyq_action" value="reassign">';
		printf( '<input type="hidden" name="category" value="%d">', (int) ( $category->id ?? 0 ) );
		echo '<p>';
		$this->render_label( 'reassign', $label );
		echo ' ';
		$this->render_category_select( 'reassign', $targets, null, null );
		$this->render_error( 'reassign' );
		echo '</p><p class="submit">';
		submit_button( __( 'Move Questions', 'easyq' ), 'primary', 'submit', false );
		printf( ' <a class="button" href="%s">%s</a>', esc_url( $back ), esc_html__( 'Cancel', 'easyq' ) );
		echo '</p></form>';
	}

	/**
	 * Renders the message of the last change and the errors of the submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message key from the last change.
	 */
	private function render_notices( string $message ): void {
		$text = match ( $message ) {
			'added'   => __( 'Category added.', 'easyq' ),
			'saved'   => __( 'Category saved.', 'easyq' ),
			'deleted' => __( 'Category deleted.', 'easyq' ),
			'moved'   => __( 'Questions moved.', 'easyq' ),
			'missing' => __( 'The category does not exist.', 'easyq' ),
			default   => '',
		};
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'missing' === $message ? 'error' : 'success', esc_html( $text ) );
		}

		if ( null !== $this->errors && $this->errors->has_errors() ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'The category could not be saved. Please correct the marked fields.', 'easyq' ) );
		}
	}

	/**
	 * Renders a warning with a link that fixes it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Warning.
	 * @param string $url  Address of the fix.
	 * @param string $link Link text.
	 */
	private function render_warning( string $text, string $url, string $link ): void {
		printf( '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>', esc_html( $text ), esc_url( $url ), esc_html( $link ) );
	}

	/**
	 * Renders a row of the edit form.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $field   Field name.
	 * @param string   $label   Label.
	 * @param callable $control Renders the control.
	 */
	private function render_row( string $field, string $label, callable $control ): void {
		echo '<tr><th scope="row">';
		$this->render_label( $field, $label );
		echo '</th><td>';
		$control();
		echo '</td></tr>';
	}

	/**
	 * Renders a text field of the add form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field       Field name.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param bool   $required    Whether the field is required.
	 * @param string $description Description.
	 */
	private function render_input( string $field, string $label, string $value, bool $required, string $description = '' ): void {
		printf( '<div class="form-field%s">', $required ? ' form-required' : '' );
		$this->render_label( $field, $label );
		$this->render_text( $field, $value, $required, $description, false );
		echo '</div>';
	}

	/**
	 * Renders a text input with its error and description.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field       Field name.
	 * @param string $value       Value.
	 * @param bool   $required    Whether the field is required.
	 * @param string $description Description.
	 * @param bool   $wide        Whether to use the regular width of the edit form.
	 */
	private function render_text( string $field, string $value, bool $required, string $description = '', bool $wide = true ): void {
		$id        = 'easyq-category-' . $field;
		$described = '' === $description ? '' : $id . '-description';

		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s>',
			esc_attr( $id ),
			esc_attr( $field ),
			esc_attr( $value ),
			$wide ? ' class="regular-text"' : '',
			$required ? ' required' : '',
			$this->error_attributes( $field, $described ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the method.
		);
		$this->render_error( $field );
		if ( '' !== $description ) {
			printf( '<p%1$s id="%2$s">%3$s</p>', $wide ? ' class="description"' : '', esc_attr( $described ), esc_html( $description ) );
		}
	}

	/**
	 * Renders the description field of the add form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function render_textarea( string $field, string $label, string $value ): void {
		echo '<div class="form-field">';
		$this->render_label( $field, $label );
		printf(
			'<textarea id="easyq-category-%1$s" name="%1$s" rows="5" cols="40"%2$s>%3$s</textarea>',
			esc_attr( $field ),
			$this->error_attributes( $field ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the method.
			esc_textarea( $value )
		);
		$this->render_error( $field );
		echo '</div>';
	}

	/**
	 * Renders the label of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name.
	 * @param string $label Label.
	 */
	private function render_label( string $field, string $label ): void {
		printf( '<label for="easyq-category-%s">%s</label>', esc_attr( $field ), esc_html( $label ) );
	}

	/**
	 * Renders a category selection.
	 *
	 * @since 0.1.0
	 *
	 * @param string                                 $field     Field name.
	 * @param array<int, array{0: Category, 1: int}> $options   Categories depth first, each with its depth.
	 * @param int|null                               $selected  Selected category ID.
	 * @param string|null                            $none      Label of the empty choice, or null for none.
	 * @param string                                 $described ID of the element that describes the field.
	 */
	private function render_category_select( string $field, array $options, ?int $selected, ?string $none, string $described = '' ): void {
		printf(
			'<select id="easyq-category-%1$s" name="%1$s"%2$s>',
			esc_attr( $field ),
			$this->error_attributes( $field, $described ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the method.
		);
		if ( null !== $none ) {
			printf( '<option value="0">%s</option>', esc_html( $none ) );
		}
		foreach ( $options as [ $category, $depth ] ) {
			printf(
				'<option value="%1$d"%2$s>%3$s%4$s</option>',
				(int) $category->id,
				selected( $selected, $category->id, false ),
				esc_html( str_repeat( '— ', $depth ) ),
				esc_html( $category->title )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the position selection: first, or after one of the siblings.
	 *
	 * @since 0.1.0
	 *
	 * @param Categories $categories Category service.
	 * @param Category   $category   Category.
	 */
	private function render_position_select( Categories $categories, Category $category ): void {
		$siblings = array_values( array_filter( $categories->children( $category->parent_id ), static fn ( Category $sibling ): bool => $sibling->id !== $category->id ) );

		echo '<select id="easyq-category-position" name="position">';
		printf( '<option value="1"%s>%s</option>', selected( 1, $category->position, false ), esc_html__( 'First', 'easyq' ) );
		foreach ( $siblings as $index => $sibling ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $index + 2,
				selected( $index + 2, $category->position, false ),
				/* translators: %s: category name. */
				esc_html( sprintf( __( 'After “%s”', 'easyq' ), $sibling->title ) )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders the error of a field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name.
	 */
	private function render_error( string $field ): void {
		$message = $this->error( $field );
		if ( '' !== $message ) {
			printf( '<p class="notice-error" id="easyq-category-%1$s-error"><strong>%2$s</strong></p>', esc_attr( $field ), esc_html( $message ) );
		}
	}

	/**
	 * Returns the attributes that mark a field as invalid and link it to its error and description.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field     Field name.
	 * @param string $described ID of the element that describes the field.
	 * @return string Escaped attributes, starting with a space, or an empty string.
	 */
	private function error_attributes( string $field, string $described = '' ): string {
		$ids = array_filter( array( '' === $this->error( $field ) ? '' : 'easyq-category-' . $field . '-error', $described ) );
		if ( array() === $ids ) {
			return '';
		}

		return ( '' === $this->error( $field ) ? '' : ' aria-invalid="true"' ) . ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"';
	}

	/**
	 * Returns the error message of a field from the last submitted form.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name.
	 * @return string Empty if the field has no error.
	 */
	private function error( string $field ): string {
		if ( null === $this->errors ) {
			return '';
		}

		// Errors that belong to no field of the form are shown at the name.
		$field_codes = array( 'title', 'slug', 'description', 'parent', 'reassign', 'children', 'not_found' );
		$codes       = 'title' === $field ? array_merge( array( 'title' ), array_diff( array_map( 'strval', $this->errors->get_error_codes() ), $field_codes ) ) : array( $field );
		if ( 'reassign' === $field ) {
			$codes = array_merge( $codes, array( 'children', 'not_found' ) );
		}

		$messages = array();
		foreach ( $codes as $code ) {
			$messages = array_merge( $messages, $this->errors->get_error_messages( $code ) );
		}

		return implode( ' ', $messages );
	}

	/**
	 * Returns a category ID from a form value in which 0 stands for none.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Form value.
	 * @return int|null
	 */
	private static function id( string $value ): ?int {
		$id = (int) $value;

		return $id > 0 ? $id : null;
	}
}
