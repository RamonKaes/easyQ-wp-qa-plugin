/**
 * Shows the points minimum of a permission only while a threshold with points
 * is chosen, as Question2Answer does. Without this script, it stays visible.
 */

/**
 * Shows or hides the points minimum of a permission.
 *
 * @param {HTMLSelectElement} select Permission.
 */
function update( select ) {
	const points = document.getElementById( select.dataset.easyqPoints );
	if ( points ) {
		points.hidden =
			! select.selectedOptions[ 0 ]?.hasAttribute( 'data-points' );
	}
}

document
	.querySelectorAll( 'select[data-easyq-points]' )
	.forEach( ( select ) => {
		update( select );
		select.addEventListener( 'change', () => update( select ) );
	} );
