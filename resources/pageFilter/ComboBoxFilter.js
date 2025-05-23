window.contentTransfer = window.contentTransfer || {};
window.contentTransfer.widget = window.contentTransfer.widget || {};

contentTransfer.widget.ComboBoxFilter = function ( cfg ) {
	cfg = cfg || {};
	this.id = cfg.id;
	this.optionData = cfg.optionData;
	cfg.options = this.optionData.map( ( item ) => ( { data: item.text } ) );
	cfg.menu = {
		filterFromInput: true
	};
	cfg.$overlay = true;
	contentTransfer.widget.ComboBoxFilter.parent.call( this, cfg );
};

OO.inheritClass( contentTransfer.widget.ComboBoxFilter, OO.ui.ComboBoxInputWidget );

contentTransfer.widget.ComboBoxFilter.prototype.getValue = function () {
	let value = contentTransfer.widget.ComboBoxFilter.parent.prototype.getValue.call( this );
	if ( !value ) {
		return false;
	}
	const key = this.getValueKey();
	for ( let i = 0; i < this.optionData.length; i++ ) {
		if ( this.optionData[ i ].text === value ) {
			value = this.optionData[ i ][ key ];
			if ( key === 'id' ) {
				return parseInt( value );
			}
			return value;
		}
	}

	return null;
};

contentTransfer.widget.ComboBoxFilter.prototype.getValidity = function () {
	return $.Deferred().resolve().promise();
};

contentTransfer.widget.ComboBoxFilter.prototype.getValueKey = function () {
	return 'id';
};
