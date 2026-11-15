<?php
/**
 * Q&A section of the user profile screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\REST\REST_Errors;
use EasyQ\Services;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Status;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Q&A profile fields, level, status and points to the WordPress profile screen.
 *
 * Everyone who may edit a user can change the profile fields. Moderators can
 * confirm, approve and block users; administrators of the plugin can set levels
 * and bonus points, but never above their own level.
 *
 * @since 0.1.0
 */
final class Profile_Screen {

	/**
	 * Nonce action prefix; the user ID is appended.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'easyq_profile_';

	/**
	 * Name of the nonce field.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NONCE_FIELD = 'easyq_profile_nonce';

	/**
	 * Hooks the section into the profile screens.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'handle_save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'handle_save' ) );
	}

	/**
	 * Outputs the Q&A section.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render( WP_User $user ): void {
		$editor = Actor::current();
		$fields = Services::user_fields()->all();

		$locked = $this->locked( $editor, $user->ID );

		wp_nonce_field( self::NONCE_ACTION . $user->ID, self::NONCE_FIELD );
		?>
		<h2><?php esc_html_e( 'Questions and answers', 'easyq' ); ?></h2>
		<?php if ( null !== $locked ) : ?>
			<p class="description"><?php echo esc_html( $locked ); ?></p>
		<?php endif; ?>
		<fieldset<?php disabled( null !== $locked ); ?>>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $field ) : ?>
				<?php $field_id = 'easyq-field-' . $field->id; ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field->display_label() ); ?></label></th>
					<td>
						<?php if ( $field->is_multi_line() ) : ?>
							<textarea id="<?php echo esc_attr( $field_id ); ?>" name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" rows="5" cols="30"><?php echo esc_textarea( Services::user_fields()->value( $user->ID, $field ) ); ?></textarea>
						<?php else : ?>
							<input type="<?php echo $field->is_link() ? 'url' : 'text'; ?>" class="regular-text" id="<?php echo esc_attr( $field_id ); ?>" name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" value="<?php echo esc_attr( Services::user_fields()->value( $user->ID, $field ) ); ?>" />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Points', 'easyq' ); ?></th>
				<td>
					<?php echo esc_html( number_format_i18n( Services::points()->get( $user->ID ) ) ); ?>
					<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?>
						<p>
							<label for="easyq-bonus"><?php esc_html_e( 'Bonus points', 'easyq' ); ?></label>
							<input type="number" class="small-text" id="easyq-bonus" name="easyq_bonus" value="<?php echo esc_attr( (string) $this->bonus( $user->ID ) ); ?>" step="1" />
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<?php // As the rows of core's profile screen: the heading of the row is the label of its field. ?>
				<?php if ( $this->can_set_levels( $editor, $user->ID ) ) : ?>
					<th scope="row"><label for="easyq-level"><?php esc_html_e( 'Level', 'easyq' ); ?></label></th>
				<?php else : ?>
					<th scope="row"><?php esc_html_e( 'Level', 'easyq' ); ?></th>
				<?php endif; ?>
				<td>
					<?php if ( $this->can_set_levels( $editor, $user->ID ) ) : ?>
						<select id="easyq-level" name="easyq_level">
							<option value=""><?php esc_html_e( '— Based on the user’s role —', 'easyq' ); ?></option>
							<?php foreach ( $this->assignable_levels( $editor, $user->ID ) as $level => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $level ); ?>" <?php selected( Services::user_levels()->assigned_level( $user->ID ), $level ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							/* translators: %s: name of the level. */
							echo esc_html( sprintf( __( 'Current level: %s', 'easyq' ), $this->level_label( Services::user_levels()->level( $user->ID ) ) ) );
							?>
						</p>
					<?php else : ?>
						<?php echo esc_html( $this->level_label( Services::user_levels()->level( $user->ID ) ) ); ?>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $this->can_set_levels( $editor, $user->ID ) ) : ?>
				<?php $this->render_category_levels( $editor, $user ); ?>
			<?php endif; ?>

			<?php if ( current_user_can( Capabilities::MODERATE ) && $editor->user_id !== $user->ID ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'easyq' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Status', 'easyq' ); ?></legend>
							<?php
							$statuses = array(
								'confirmed' => array( User_Status::is_confirmed( $user->ID ), __( 'Email address confirmed', 'easyq' ) ),
								'approved'  => array( ! User_Status::awaits_approval( $user->ID ), __( 'Approved', 'easyq' ) ),
								'blocked'   => array( User_Status::is_blocked( $user->ID ), __( 'Blocked from posting, voting and flagging', 'easyq' ) ),
							);
							foreach ( $statuses as $status => [ $is_set, $label ] ) :
								?>
								<?php $fixed = 'blocked' === $status && ! $is_set && ! User_Status::may_be_blocked( $user->ID ); ?>
								<label><input type="checkbox" name="<?php echo esc_attr( 'easyq_' . $status ); ?>" value="1" <?php checked( $is_set ); ?> <?php disabled( $fixed ); ?> /> <?php echo esc_html( $label ); ?></label><br />
								<?php if ( $fixed ) : ?>
									<span class="description"><?php esc_html_e( 'Moderators and higher levels cannot be blocked.', 'easyq' ); ?></span><br />
								<?php endif; ?>
								<?php // The state the form shows: saving changes only what the moderator changed, not what changed since. ?>
								<input type="hidden" name="<?php echo esc_attr( 'easyq_shown[' . $status . ']' ); ?>" value="<?php echo $is_set ? '1' : '0'; ?>" />
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		</fieldset>
		<?php
	}

	/**
	 * Saves the Q&A section after verifying the nonce.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id ID of the edited user.
	 */
	public function handle_save( int $user_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $user_id ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$input = array();
		foreach ( array( 'easyq_fields', 'easyq_bonus', 'easyq_level', 'easyq_category_levels', 'easyq_confirmed', 'easyq_approved', 'easyq_blocked', 'easyq_shown' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_textarea_field' );
			}
		}

		$this->save( $user_id, $input, Actor::current() );
	}

	/**
	 * Saves the Q&A section from sanitized input, checking every change against the editor's capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $user_id ID of the edited user.
	 * @param array<string, mixed> $input   Submitted values.
	 * @param Actor                $editor  Who saves the profile.
	 */
	public function save( int $user_id, array $input, Actor $editor ): void {
		$editor_id = (int) $editor->user_id;

		// Locked as the REST API locks the profile: while read-only, and for a user who may not change it.
		if ( ! user_can( $editor_id, 'edit_user', $user_id ) || null !== $this->locked( $editor, $user_id ) ) {
			return;
		}

		$values = is_array( $input['easyq_fields'] ?? null ) ? $input['easyq_fields'] : array();
		foreach ( Services::user_fields()->all() as $field ) {
			if ( isset( $values[ $field->id ] ) && is_string( $values[ $field->id ] ) ) {
				Services::user_fields()->set_value( $user_id, $field, $values[ $field->id ] );
			}
		}

		if ( user_can( $editor_id, Capabilities::MANAGE ) && isset( $input['easyq_bonus'] ) && is_numeric( $input['easyq_bonus'] ) ) {
			$bonus = (int) $input['easyq_bonus'];
			if ( $bonus !== $this->bonus( $user_id ) ) {
				Services::points()->set_bonus( $user_id, $bonus );
			}
		}

		if ( $this->can_set_levels( $editor, $user_id ) ) {
			$this->save_levels( $user_id, $input, $editor );
		}

		if ( user_can( $editor_id, Capabilities::MODERATE ) && $editor_id !== $user_id ) {
			$this->save_status( $user_id, $input, $editor );
		}
	}

	/**
	 * Tells why the Q&A section cannot be changed, as `POST /users/me` refuses it: while the Q&A
	 * area is read-only, and on the own profile of a user who is blocked, or who must confirm
	 * their address or be approved first. Question2Answer does not save the profile of a blocked
	 * user either.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $editor  Who edits the profile.
	 * @param int   $user_id The user being edited.
	 * @return string|null The reason, or null if the section can be changed.
	 */
	private function locked( Actor $editor, int $user_id ): ?string {
		if ( Services::permissions()->is_read_only_for( $editor->user_id ) ) {
			return __( 'The Q&A area is read-only at the moment, so this section cannot be changed.', 'easyq' );
		}

		$reason = $editor->user_id === $user_id && ! user_can( $user_id, Capabilities::MANAGE ) ? Services::permissions()->error( null, $editor ) : null;

		return null === $reason ? null : REST_Errors::permission( $reason )->get_error_message();
	}

	/**
	 * Saves the level and category levels.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Submitted values.
	 * @param Actor                $editor  Who saves the profile.
	 */
	private function save_levels( int $user_id, array $input, Actor $editor ): void {
		$assignable = $this->assignable_levels( $editor, $user_id );
		$levels     = Services::user_levels();

		if ( array_key_exists( 'easyq_level', $input ) ) {
			$level = is_numeric( $input['easyq_level'] ) ? (int) $input['easyq_level'] : null;
			if ( null === $level || isset( $assignable[ $level ] ) ) {
				$levels->set_level( $editor, $user_id, $level );
			}
		}

		$category_levels = is_array( $input['easyq_category_levels'] ?? null ) ? $input['easyq_category_levels'] : array();
		foreach ( $category_levels as $category_id => $level ) {
			if ( ! is_numeric( $category_id ) || null === Services::categories()->find( (int) $category_id ) ) {
				continue;
			}

			$level = is_numeric( $level ) ? (int) $level : null;
			if ( null === $level || isset( $assignable[ $level ] ) ) {
				$current = $levels->category_levels( $user_id )[ (int) $category_id ] ?? null;
				if ( $current !== $level ) {
					$levels->set_category_level( $editor, $user_id, (int) $category_id, $level );
				}
			}
		}
	}

	/**
	 * Saves the confirmation, approval and block status.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Submitted values.
	 * @param Actor                $editor  Who saves the profile.
	 */
	private function save_status( int $user_id, array $input, Actor $editor ): void {
		$shown = is_array( $input['easyq_shown'] ?? null ) ? $input['easyq_shown'] : array();

		foreach ( array( 'confirmed', 'approved', 'blocked' ) as $status ) {
			// Only a status the form showed, and only if the moderator changed it: a form opened before
			// the user confirmed their address does not take the confirmation back.
			if ( ! isset( $shown[ $status ] ) || ! empty( $input[ 'easyq_' . $status ] ) === ( '1' === $shown[ $status ] ) ) {
				continue;
			}

			$set = ! empty( $input[ 'easyq_' . $status ] );
			match ( $status ) {
				'confirmed' => $set ? User_Status::confirm( $editor, $user_id ) : User_Status::unconfirm( $user_id ),
				'approved'  => $set ? User_Status::approve( $editor, $user_id ) : User_Status::unapprove( $user_id ),
				default     => $set ? User_Status::block( $editor, $user_id ) : User_Status::unblock( $editor, $user_id ),
			};
		}
	}

	/**
	 * Outputs the category level rows.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor   $editor Who edits the profile.
	 * @param WP_User $user   The user being edited.
	 */
	private function render_category_levels( Actor $editor, WP_User $user ): void {
		$categories = Services::categories()->all();
		if ( array() === $categories ) {
			return;
		}

		$current = Services::user_levels()->category_levels( $user->ID );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Levels in categories', 'easyq' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Levels in categories', 'easyq' ); ?></legend>
					<?php foreach ( $categories as $category ) : ?>
						<?php $select_id = 'easyq-category-level-' . $category->id; ?>
						<p>
							<label for="<?php echo esc_attr( $select_id ); ?>"><?php echo esc_html( implode( ' › ', array_map( static fn ( $ancestor ): string => $ancestor->title, Services::categories()->path( $category->id ) ) ) ); ?></label><br />
							<select id="<?php echo esc_attr( $select_id ); ?>" name="easyq_category_levels[<?php echo esc_attr( (string) $category->id ); ?>]">
								<option value=""><?php esc_html_e( '— No special level —', 'easyq' ); ?></option>
								<?php foreach ( $this->assignable_levels( $editor, $user->ID ) as $level => $label ) : ?>
									<?php if ( $level >= Levels::EXPERT ) : ?>
										<option value="<?php echo esc_attr( (string) $level ); ?>" <?php selected( $current[ $category->id ] ?? null, $level ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'A level in a category also applies to its subcategories.', 'easyq' ); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Checks whether the editor may change the levels of a user.
	 *
	 * Nobody can change their own level, and the edited user's level must not be above the editor's.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $editor  Who edits the profile.
	 * @param int   $user_id The user being edited.
	 * @return bool
	 */
	private function can_set_levels( Actor $editor, int $user_id ): bool {
		if ( null === $editor->user_id || $editor->user_id === $user_id || ! user_can( $editor->user_id, Capabilities::MANAGE ) ) {
			return false;
		}

		return ( Services::user_levels()->level( $user_id ) ?? 0 ) <= $this->editor_level( $editor );
	}

	/**
	 * Returns the levels an editor may assign: up to their own level, and up to Editor for a
	 * blocked user, as Question2Answer limits it (`qa-include/pages/user-profile.php`).
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $editor  Who edits the profile.
	 * @param int   $user_id The user being edited.
	 * @return array<int, string>
	 */
	private function assignable_levels( Actor $editor, int $user_id ): array {
		$maximum = $this->editor_level( $editor );
		if ( User_Status::is_blocked( $user_id ) ) {
			$maximum = min( $maximum, Levels::EDITOR );
		}

		return array_filter( Levels::labels(), static fn ( int $level ): bool => $level <= $maximum, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Returns the level of the editor; WordPress administrators count as Q&A administrators.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $editor Who edits the profile.
	 * @return int
	 */
	private function editor_level( Actor $editor ): int {
		$level = Services::user_levels()->level( $editor->user_id ) ?? 0;

		return null !== $editor->user_id && user_can( $editor->user_id, 'manage_options' ) ? max( $level, Levels::ADMIN ) : $level;
	}

	/**
	 * Returns the bonus points of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function bonus( int $user_id ): int {
		$wpdb = \EasyQ\Database\DB::wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT bonus FROM %i WHERE user_id = %d', \EasyQ\Database\Schema::table( 'userpoints' ), $user_id )
		);
	}

	/**
	 * Returns the name of a level.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $level Level.
	 * @return string
	 */
	private function level_label( ?int $level ): string {
		return Levels::labels()[ $level ?? Levels::BASIC ] ?? (string) $level;
	}
}
