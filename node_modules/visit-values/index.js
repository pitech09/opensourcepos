'use strict'

/*
	visit all the children of a javascript object

	the visit function signature is:

	function(value, key, parent) {}
*/
let visit = module.exports = (current, fn) => {

	for (let i = 0, keys = Object.keys(current); i < keys.length; i++) {
		let key = keys[i]
		let value = current[key]

		if (value === undefined || value === null) continue

		if (typeof value === 'object' || typeof value === 'function') {
			visit(current[key], fn)
			continue
		}

		let proceed = fn(current[key], key, current)

		// returning false (and only false)
		// from the visitor function will stop the 
		// visitations
		if (proceed === false) {
			break;
		}
	}
}