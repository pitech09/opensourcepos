'use strict'

const assert = require('assert')
const visitValues = require('./index.js')

describe('visit', function () {
	it('visits', function() {
		let tree = {
			a: {
				b: {
					c: 'd'
				},
				f: [1, 2]
			},
			c: {
				x: 'l',
				f: function () {}
			}
		}

		let results = []

		visitValues(tree, function(v, k, p) {
			//push a stringified form of the arguments for easy lookup later
			results.push(v + ' ' + k + ' ' + JSON.stringify(p))			
		})

		assert.strictEqual(results.length, 4)
		
		assert(results.indexOf('d c {"c":"d"}') > -1)
		assert(results.indexOf('1 0 [1,2]')> -1)
		assert(results.indexOf('2 1 [1,2]')> -1)
		assert(results.indexOf('l x {"x":"l"}')> -1)
	})	
})