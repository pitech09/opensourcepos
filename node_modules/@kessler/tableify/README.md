@kessler/tableify
-------------

A fork of [tableify](https://github.com/wankdanker/node-tableify).
All the cli rekated code and dependencies is removed.
Add public methods for generating a complete html document with styling:
```js
var tableify = require('tableify')
// just the table
tableify({ foo: 'bar' })

// html document with style
tableify.htmlDoc({ foo: 'bar' })
tableify.fancyHtmlDoc({ foo: 'bar' })
```

From any JavaScript object, HTML tables create.

description
-----------

Given any Javascript object including Object, Array, Number, Date, null, undefined, primitives,
etc, `tableify` generates HTML tables that represent each object. In the case of Arrays containing
Objects (hash tables), it will generate a table with a header row containing the key names of the
object found in the first element of the array.

Every value to be output to the table is processed through `tableify` recursively so objects containing
other objects will result in tables within tables.

For each `td` cell that is created, a class is generated based on the `constructor.name` of the value, or
`null` if the value is null.

install
-------

```bash
npm install @kessler/tableify
```

usage
-----

```javascript
var tableify = require('tableify');

// embed the table in an html document with some styling
var html = tableify.htmlDoc({
	someArrayOfObjects : [
		{ a : 1, b : 2, c : 3  }
		, { a : 2, b : 3, c : 4 }
		, { a : 3, b : 4, c : 5 }
	]
	, someObject : {
		key1 : 'value1'
		, someArray : [
			'value2'
			, 'value3'
			, 'value4'
			, 'value5'
		]
		, someArrayOfObjects : [
			{ key2 : 123 }
			, { key2 : 234 }
			, { key2 : 345 }
		]
	}
});

console.log(html);
```

license
-------

MIT

