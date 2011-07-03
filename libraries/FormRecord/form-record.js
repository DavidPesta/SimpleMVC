/*
* Copyright (c) 2011 David Pesta, http://www.simplemvc.org
* Licensed under the MIT License.
* You should have received a copy of the MIT License along with this program.
* If not, see http://www.opensource.org/licenses/mit-license.php
*/

// A field that gets "validated" or is "valid" or "invalid" means that the field data has been checked, but not neccessarily visibly displayed to the user
// A field that gets "activated" means that the field's validation display is turned on and visible to the user (showing a valid or invalid display)
//   You can activate a field that is already activated: that simply means that the display is reset to display the current valid or invalid state.
function createValidator( form, rules, customValidation, customMessages, updatedElements ) {
	var self = {
		_formId: $( form ).attr( 'id' ),
		_form: form,
		_rules: rules,
		_fieldStates: {},
		_defaultMessages: {
			valid: "",
			required: "This field is required.",
			integer: "Please enter a whole number with only digits.",
			decimal: "Please enter a number.",
			date: "Please enter a properly formatted date.",
			time: "Please enter a properly formatted time.",
			minValue: "Please enter a value greater than or equal to ?.",
			maxValue: "Please enter a value less than or equal to ?.",
			minLength: "Please enter at least ? characters.",
			maxLength: "Please enter no more than ? characters.",
			ajaxChecking: "Checking"
		},
		_customValidation: ( customValidation ? customValidation : {} ),
		_customMessages: ( customMessages ? customMessages : {} ),
		_updatedElements: ( updatedElements ? updatedElements : [ "-field", "-label", "-input", "", "-status" ] ),
		_event: null,
		
		init: function() {
			$textInputs = $( "#" + self._formId + " .field-input-text" );
			
			$textInputs.each( function() {
				var fieldName = $( this ).attr( 'id' );
				
				// Set the initial field states for the field
				self._fieldStates[ fieldName ] = {
					valid: null,
					activated: false,
					message: "",
					ajaxValidate: false
				};
				
				$( this ).bind( 'focusout', function() {
					if( self._fieldStates[ fieldName ].ajaxValidate == false ) { // if ajaxValidate is invoked for the field, focusout shouldn't operate
						// Validate the field and set internal flags whether it is valid or invalid
						self.validate( fieldName );
						
						// Run the custom validation function for this field here, pass to it "focusout" and have IT check for activated if applicable
						self.customValidate( fieldName, "focusout" );
						
						// Immediately activate the field if it is not required or if there are contents in the box or if box is already flagged as activated
						if(
							( typeof self._rules[ fieldName ] !== 'undefined' && self._rules[ fieldName ].required == 0 ) || 
							$( this ).val().length > 0 || 
							self.isActivated( fieldName )
						) {
							self.activate( fieldName );
						}
						
						// Check to see if the submit button can be enabled
						self.enableDisableSubmit();
					}
				});
				
				$( this ).bind( 'keyup', function() {
					// Validate the field and set internal flags whether it is valid or invalid
					self.validate( fieldName );
					
					// Run the custom validation function for this field here, pass to it "keyup" and have IT check for activated if applicable
					self.customValidate( fieldName, "keyup" );
					
					// Only (re)activate the field if the box is already flagged as activated
					if( self.isActivated( fieldName ) ) self.activate( fieldName );
					
					// Always check to see if the submit button can be enabled on every keystroke, regardless of a field's activation state
					self.enableDisableSubmit();
				});
			});
			
			// Need to perform a full form validation here to populate all the field states for "valid"
			// Gotcha: This will call customValidate functions immediately on page load, so check for "initial" event to prevent
			//         whatever functionality isn't wanted immediately.
			self.validateForm( "initial" );
			
			// Clicking on the submit button needs to cause a complete form validation, and if invalid, abort and activate all of the form elements
			$( "#" + self._formId + " .submit" ).click( function() {
				self.validateForm( "submit" );
				if( self.isFormValid() == false ) {
					self.activateForm();
					return false;
				}
			});
		},
		
		validate: function( fieldName ) {
			var fieldValue = $.trim( $( "#" + fieldName ).val() );
			
			// If there are no rules on this field, assume it is a required field and only validate it if it contains data
			if( typeof self._rules[ fieldName ] === "undefined" ) {
				if( fieldValue == "" ) {
					self.setInvalid( fieldName );
					return false;
				}
				else {
					self.setValid( fieldName );
					return true;
				}
			}
			
			var fieldRules = self._rules[ fieldName ];
			
			var valid = true;
			
			var intValue = parseInt( fieldValue, 10 );
			
			if( typeof fieldRules.minValue !== 'undefined' && ! isNaN( intValue ) ) {
				if( intValue < fieldRules.minValue ) {
					valid = false;
					self.setMessage( fieldName, "minValue", fieldRules.minValue );
				}
			}
			
			if( typeof fieldRules.maxValue !== 'undefined' && ! isNaN( intValue ) ) {
				if( intValue > fieldRules.maxValue ) {
					valid = false;
					self.setMessage( fieldName, "maxValue", fieldRules.maxValue );
				}
			}
			
			if( typeof fieldRules.minLength !== 'undefined' ) {
				if( fieldValue.length < fieldRules.minLength ) {
					valid = false;
					self.setMessage( fieldName, "minLength", fieldRules.minLength );
				}
			}
			
			if( typeof fieldRules.maxLength !== 'undefined' ) {
				if( fieldValue.length > fieldRules.maxLength ) {
					valid = false;
					self.setMessage( fieldName, "maxLength", fieldRules.maxLength );
				}
			}
			
			if( fieldRules.required == true && fieldValue == "" ) {
				valid = false;
				self.setMessage( fieldName, "required" );
			}
			
			if( fieldRules.type == "integer" && /^\d+$/.test( fieldValue ) == false ) {
				valid = false;
				self.setMessage( fieldName, "integer" );
			}
			
			if( fieldRules.type == "decimal" && /^[-+]?[0-9]+(\.[0-9]+)?$/.test( fieldValue ) == false ) {
				valid = false;
				self.setMessage( fieldName, "decimal" );
			}
			
			if( fieldRules.type == "date" && self.isValidDate( fieldValue ) == false ) {
				valid = false;
				self.setMessage( fieldName, "date" );
			}
			
			if( fieldRules.type == "time" && self.isValidTime( fieldValue ) == false ) {
				valid = false;
				self.setMessage( fieldName, "time" );
			}
			
			if( valid == true ) self.setValid( fieldName );
			if( valid == false ) self.setInvalid( fieldName );
			
			return valid;
		},
		
		customValidate: function( fieldName, event ) {
			if( typeof self._customValidation[ fieldName ] !== "undefined" ) {
				self._event = event;
				self._customValidation[ fieldName ]( self );
			}
		},
		
		validateForm: function( event ) {
			event = typeof( event ) != 'undefined' ? event : "validate";
			
			$.each( self._fieldStates, function( fieldName, value ) {
				self.validate( fieldName );
				self.customValidate( fieldName, event ); // Gotcha: Make sure the custom validation keeps the "validate" event in mind
			});
		},
		
		activateForm: function() {
			$.each( self._fieldStates, function( fieldName, value ) {
				self.activate( fieldName );
			});
		},
		
		isFormValid: function() {
			var valid = true;
			$.each( self._fieldStates, function( key, value ) {
				if( value.valid !== true ) {
					valid = false;
					return;
				}
			});
			return valid;
		},
		
		isValid: function( fieldName ) {
			return self._fieldStates[ fieldName ].valid;
		},
		
		setValid: function( fieldName ) {
			self._fieldStates[ fieldName ].valid = true;
			self.setMessage( fieldName, "valid" ); // This is convenient to have here. We can always setMessage again immediately after calling setValid.
		},
		
		setInvalid: function( fieldName ) {
			self._fieldStates[ fieldName ].valid = false;
		},
		
		setPending: function( fieldName ) {
			self._fieldStates[ fieldName ].valid = null;
			self.setMessage( fieldName, "ajaxChecking" ); // This is convenient to have here. We can always setMessage again immediately after calling setPending.
		},
		
		isActivated: function( fieldName ) {
			return self._fieldStates[ fieldName ].activated;
		},
		
		activate: function( fieldName ) {
			self._fieldStates[ fieldName ].activated = true;
			
			var updatedElements = self._updatedElements;
			if( BrowserDetect.browser == "Explorer" && BrowserDetect.version < 9 ) var updatedElements = [ "-status" ];
			
			$.each( updatedElements, function( key, value ) {
				if( self.isValid( fieldName ) === true ) $( "#" + fieldName + value ).removeClass( "invalid pending" ).addClass( "valid" );
				if( self.isValid( fieldName ) === false ) $( "#" + fieldName + value ).removeClass( "valid pending" ).addClass( "invalid" );
				if( self.isValid( fieldName ) === null ) $( "#" + fieldName + value ).removeClass( "valid invalid" ).addClass( "pending" );
			});
			
			$( "#" + fieldName + "-status" ).html( self.getMessage( fieldName ) );
		},
		
		deactivate: function( fieldName ) {
			self._fieldStates[ fieldName ].activated = false;
			
			var updatedElements = self._updatedElements;
			if( BrowserDetect.browser == "Explorer" && BrowserDetect.version < 9 ) var updatedElements = [ "-status" ];
			
			$.each( updatedElements, function( key, value ) {
				$( "#" + fieldName + value ).removeClass( "valid invalid pending" );
			});
		},
		
		enableDisableSubmit: function() {
			var isFormValid = self.isFormValid();
			if( isFormValid == true ) $( "#" + self._formId + " .submit.disabled" ).removeAttr( 'disabled' );
			if( isFormValid == false ) $( "#" + self._formId + " .submit.disabled" ).attr( 'disabled', 'disabled' );
		},
		
		setMessage: function( fieldName, message, replacement ) {
			self._fieldStates[ fieldName ].message = message;
			if( typeof self._defaultMessages[ message ] !== "undefined" ) self._fieldStates[ fieldName ].message = self._defaultMessages[ message ];
			if( typeof self._customMessages[ message ] !== "undefined" ) self._fieldStates[ fieldName ].message = self._customMessages[ message ];
			if( typeof replacement !== 'undefined' ) {
				self._fieldStates[ fieldName ].message = self._fieldStates[ fieldName ].message.replace( "?", replacement );
			}
		},
		
		getMessage: function( fieldName ) {
			return self._fieldStates[ fieldName ].message;
		},
		
		isValidDate: function( value ) {
			var days = [ 0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
			var dateParts = null;
			
			dateParts = value.match( /^(\d{1,2})[./-](\d{1,2})[./-](\d{2}|\d{4})$/ );
			
			if( dateParts ) {
				var year = parseInt( dateParts[ 3 ], 10 );
				var month = parseInt( dateParts[ 1 ], 10 );
				var day = parseInt( dateParts[ 2 ], 10 );
				
				var isLeapYear = ( year % 4 != 0 ? false : ( year % 100 != 0 ? true: ( year % 1000 != 0 ? false : true ) ) );
				
				var test = ( month == 2 && isLeapYear == true && 29 || days[ month ] || 0 );
				
				return 1 <= day && day <= test;
			}
			else {
				return false;
			}
		},
		
		isValidTime: function( value ) {
			timeParts = value.match( /^(\d{1,2}):(\d{2})(:(\d{2}))?(\s?(AM|am|PM|pm))?$/ );
			
			if( timeParts ) {
				var hour = parseInt( timeParts[ 1 ], 10 );
				var minute = parseInt( timeParts[ 2 ], 10 );
				var second = parseInt( timeParts[ 4 ], 10 );
				var ampm = timeParts[ 6 ];
				
				if( second == "" ) second = null;
				if( ampm == "" ) ampm = null;
				
				if( hour < 0  || hour > 23 ) return false;
				if( hour > 12 && ampm != null ) return false;
				if( minute < 0 || minute > 59 ) return false;
				if( second != null && ( second < 0 || second > 59 ) ) return false;
				
				return true;
			}
			else {
				return false;
			}
		},
		
		ajaxValidate: function ( fieldName, action, time ) {
			time = typeof( time ) != 'undefined' ? time : 2000;
			
			if( self._event == "keyup" ) {
				$( "#" + fieldName ).stopTime( "ajaxValidate" ); // whether valid or invalid, any ajaxValidate timer should stop now
				self._fieldStates[ fieldName ].ajaxValidate = true;
				
				if( self.isValid( fieldName ) == true ) {
					var value = $( "#" + fieldName ).val();
					
					self.activate( fieldName );
					self.setPending( fieldName );
					
					$( "#" + fieldName ).oneTime( time, "ajaxValidate", function() {
						$.post( action, { fieldName: fieldName, value: value }, function( response ) {
							if( response.valid == true ) {
								self.setValid( fieldName );
							}
							else {
								self.setInvalid( fieldName );
								self.setMessage( fieldName, response.message );
							}
							self.activate( fieldName );
							self.enableDisableSubmit();
						}, "json" );
					});
				}
			}
		}
	};
	
	self.init();
	
	return self;
};
