/**
 * Disables the points minimum of a permission while the chosen threshold needs
 * no points, as core disables the page selection under Settings → Reading. The
 * field stays in place, so nothing moves; without this script, it stays enabled.
 */

/**
 * Enables or disables the points minimum of a permission.
 *
 * @param {HTMLSelectElement} select Permission.
 */
function update( select ) {
	const points = document.getElementById( select.dataset.easyqPoints );
	if ( points ) {
		points.disabled =
			! select.selectedOptions[ 0 ]?.hasAttribute( 'data-points' );
	}
}

document
	.querySelectorAll( 'select[data-easyq-points]' )
	.forEach( ( select ) => {
		update( select );
		select.addEventListener( 'change', () => update( select ) );
	} );
