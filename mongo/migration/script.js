/* 
 * General (idempotent) DB migration script goes here.
 * Please try to avoid using migration script and instead make special treatment in the code!
 */

// =============================== Helper functions ============================

function addFieldToConfig(lastConf, fieldConf, entityName) {
	var fields = lastConf[entityName]['fields'];
	var found = false;
	for (var field_key in fields) {
		if (fields[field_key].field_name === fieldConf.field_name) {
			found = true;
		}
	}
	if(!found) {
		fields.push(fieldConf);
	}
	lastConf[entityName]['fields'] = fields;

	return lastConf;
}

// =============================================================================

// BRCD-1077 Add new custom 'tariff_category' field to Products(Rates).
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
var fields = lastConfig['rates']['fields'];
var found = false;
for (var field_key in fields) {
	if (fields[field_key].field_name === "tariff_category") {
		found = true;
	}
}
if(!found) {
	fields.push({
		"system":false,
		"select_list":true,
		"display":true,
		"editable":true,
		"field_name":"tariff_category",
		"default_value":"retail",
		"show_in_list":true,
		"title":"Tariff category",
		"mandatory":true,
		"select_options":"retail",
		"changeable_props": ["select_options"]
	});
}
lastConfig['rates']['fields'] = fields;

// BRCD-1078: add rate categories
for (var i in lastConfig['file_types']) {
	var firstKey = Object.keys(lastConfig['file_types'][i]['rate_calculators'])[0];
	var secKey = Object.keys(lastConfig['file_types'][i]['rate_calculators'][firstKey])[0];
	if (secKey == 0) {
		lastConfig['file_types'][i]['rate_calculators']['retail'] = {};
	for (var usaget in lastConfig['file_types'][i]['rate_calculators']) {
			if (usaget === 'retail') {
				continue;
			}
			lastConfig['file_types'][i]['rate_calculators']['retail'][usaget] = lastConfig['file_types'][i]['rate_calculators'][usaget];
			delete lastConfig['file_types'][i]['rate_calculators'][usaget];
		}
	}
}

// BRCD-1077 update all products(Rates) tariff_category field.
db.rates.update({'tariff_category': {$exists: false}},{$set:{'tariff_category':'retail'}},{multi:1});

// BRCD-938: Option to not generate pdfs for the cycle
if (typeof lastConfig['billrun']['generate_pdf']  === 'undefined') {
	lastConfig['billrun']['generate_pdf'] = {"v": true ,"t" : "Boolean"};
}

// BRCD-441 -Add plugin support
if (!lastConfig['plugins']) {
	lastConfig.plugins = ["calcCpuPlugin", "csiPlugin", "autorenewPlugin"];
}

//-------------------------------------------------------------------
// BRCD-1278 - backward support for new template
if(lastConfig.invoice_export) {
	lastConfig.invoice_export.header = "/application/views/invoices/header/header_tpl.html";
	lastConfig.invoice_export.footer = "/application/views/invoices/footer/footer_tpl.html";
}

//BRCD-1229 - Input processor re-enabled when not requested
for (var i in lastConfig['file_types']) {
	if (lastConfig['file_types'][i]['enabled'] === undefined) {
		lastConfig['file_types'][i]['enabled'] = true;
	}
}

// BRCD-1278 : add minutes:seconds support  for time display
var found =false;
for(var i in lastConfig["property_types"][0]["uom"]) {
		if(lastConfig["property_types"][0]["uom"][i]['name'] == "mmss" ) {
				found = true;
		}
}
if(!found) { 
		lastConfig["property_types"][0]["uom"].push({"name":"mmss","label":"mm:ss","function_name":"parseTime","arguments":{"format":"_I:s"}});
}
lastConfig["property_types"][0]['invoice_uom'] = "mmss";

// BRCD-1152: Add service activation date to each cdr generated on the billing cycle
if(!lastConfig['lines']) {
	lastConfig['lines'] = {};
}
if(!lastConfig['lines']['fields']) {
	lastConfig['lines']['fields'] = [];
}
var idx = 0;
for (var i in lastConfig['lines']['fields']) {
	if (lastConfig['lines']['fields'][i]['field_name'] == 'foreign.activation_date') {
		idx = i;
		break;
	}
	idx = i+1;
}
var addField = {
	field_name : "foreign.activation_date",
	foreign : { 
		entity : "service",
		field  :"start",
		translate : {
			type : "unixTimeToString",
			format : "Y-m-d H:i:s"
		}
	}
};
if(lastConfig['lines']['fields'].length > idx) {
	lastConfig['lines']['fields'][idx] = addField;
} else {
	lastConfig['lines']['fields'].push(addField);
}

//BRCD-1324 - Save CreditGuard last 4 digits in the account active payment gateway field
db.subscribers.find({type:"account", 'payment_gateway.active.name':"CreditGuard"}).forEach(
		function(obj) {
			var activeGateway = obj.payment_gateway.active;
			var token = activeGateway.card_token;
			var fourDigits = token.substring(token.length - 4, token.length);
			activeGateway.four_digits = fourDigits;
			db.subscribers.save(obj)
		}
)

// BRCD-1353: CreditGuard fixes
var paymentGateways = lastConfig['payment_gateways'];
for (var paymentGateway in paymentGateways) {
	if (paymentGateways[paymentGateway].name === "CreditGuard" && paymentGateways[paymentGateway]['params']['terminal_id'] !== undefined) {
		if (paymentGateways[paymentGateway]['params']['redirect_terminal'] === undefined || paymentGateways[paymentGateway]['params']['charging_terminal'] === undefined) {
			paymentGateways[paymentGateway]['params']['redirect_terminal'] = paymentGateways[paymentGateway]['params']['terminal_id'];
			paymentGateways[paymentGateway]['params']['charging_terminal'] = paymentGateways[paymentGateway]['params']['terminal_id'];
			delete paymentGateways[paymentGateway]['params']['terminal_id'];
		}
	}
}

// BRCD-1390 - Add activation_date field to subscriber
db.subscribers.find({activation_date:{$exists:0}, type:'subscriber'}).forEach(
	function(obj) {
		var activationDate = -1;
		db.subscribers.find({sid:obj.sid, aid:obj.aid, activation_date:{$exists:0}}).sort({'from': 1}).forEach(
			function(obj2) {
				if (activationDate == -1) {
					activationDate = obj2.from;
				}
				obj2.activation_date = activationDate;
				db.subscribers.save(obj2);
			}
		);
	}
);

// BRCD-1402 - Add activation_date field to subscriber
if(lastConfig.invoice_export) {
	if(lastConfig.invoice_export.header && lastConfig.invoice_export.header.match(/^\/application\/views\/invoices/)) {
		lastConfig.invoice_export.header = lastConfig.invoice_export.header.replace(/^\/application\/views\/invoices/,'');
	}
	if(lastConfig.invoice_export.footer && lastConfig.invoice_export.footer.match(/^\/application\/views\/invoices/)) {
		lastConfig.invoice_export.footer =lastConfig.invoice_export.footer.replace(/^\/application\/views\/invoices/,'');
	}
}

//BRCD-1374 : Add taxation support services 
var vatableField ={
					"system":true,
					"select_list" : false,
					"display" : true,
					"editable" : true,
					"multiple" : false,
					"field_name" : "vatable",
					"unique" : false,
					"default_value" : "1",
					"title" : "This service is taxable",
					"mandatory" : false,
					"type" : "boolean",
					"select_options" : ""
	};
lastConfig = addFieldToConfig(lastConfig, vatableField, 'services')

//BRCD-1272 - Generate Creditguard transactions in csv file + handle rejections file
for (var i in lastConfig['payment_gateways']) {
	if (lastConfig["payment_gateways"][i]['name'] == "CreditGuard") {
		if (typeof lastConfig['payment_gateways'][i]['receiver']  === 'undefined' && typeof lastConfig['payment_gateways'][i]['export']  === 'undefined' ) {
			lastConfig["payment_gateways"][i].receiver = {};
			lastConfig["payment_gateways"][i].export = {};
		}
	}
}

//BRCD-1411 - Multiple conditions for usage type mapping.
var fileTypes = lastConfig['file_types'];
for (var fileType in fileTypes) {
	if (typeof fileTypes[fileType]['processor']['usaget_mapping'] !== 'undefined') {
		var usagetMapping = fileTypes[fileType]['processor']['usaget_mapping'];
		for (var mapping in usagetMapping) {
			if (typeof fileTypes[fileType]['processor']['usaget_mapping'][mapping]['conditions'] === 'undefined') {
				var conditions = [];
				var condition = {
					"src_field": usagetMapping[mapping]["src_field"],
					"pattern": usagetMapping[mapping]["pattern"],
					"op": "$eq",
				};
				conditions.push(condition);
				fileTypes[fileType]['processor']['usaget_mapping'][mapping]["conditions"] = conditions;
				delete fileTypes[fileType]['processor']['usaget_mapping'][mapping]["src_field"];
				delete fileTypes[fileType]['processor']['usaget_mapping'][mapping]["pattern"];
			}
		}
	}
}

// BRCD-1415 - add invoice when ready email template
if(!lastConfig.email_templates) {
	lastConfig.email_templates = {
    "invoice_ready": {
      "subject": "Your invoice is ready",
      "content": "<pre>\nHello [[customer_firstname]],\n\nThe invoice for [[cycle_range]] is ready and is attached to this email.\nFor any questions, please contact us at [[company_email]].\n\n[[company_name]]</pre>\n",
      "html_translation": [
        "invoice_id",
        "invoice_total",
        "invoice_due_date",
        "cycle_range",
        "company_email",
        "company_name"
      ]
    }
  };
}

// BRCD-1415 - add system field to account (invoice_shipping_method)
var fields = lastConfig['subscribers']['account']['fields'];
var found = false;
for (var field_key in fields) {
	if (fields[field_key].field_name === "invoice_shipping_method") {
		found = true;
	}
}
if(!found) {
	fields.push({
		"system":false,
		"select_list":true,
		"display":true,
		"editable":true,
		"field_name":"invoice_shipping_method",
		"default_value":"email",
		"show_in_list":true,
		"title":"Invoice shipping method",
		"mandatory":false,
		"select_options":"email",
		"changeable_props": ["select_options"]
	});
}
lastConfig['subscribers']['account']['fields'] = fields;


// BRCD-1458 - Add support for hh:mm:ss, mm:ss "units" in input processor volume stage.
var propertyTypes = lastConfig['property_types'];
for (var i in propertyTypes) {
	if (propertyTypes[i]['type'] === 'time') {
		var timeProperty = lastConfig['property_types'][i];
		if (timeProperty['uom']) {
			for (var j in timeProperty['uom']) {
				if (timeProperty['uom'][j]['name'] === 'hhmmss' || timeProperty['uom'][j]['name'] === 'mmss') {
					lastConfig['property_types'][i]['uom'][j]['convertFunction'] = 'formatedTimeToSeconds'; 
				}
			}
		}
	}
}


db.rebalance_queue.ensureIndex({"creation_date": 1}, {unique: false, "background": true})

// BRCD-1443 - Wrong billrun field after a rebalance
db.billrun.update({'attributes.invoice_type':{$ne:'immediate'}, billrun_key:{$regex:/^[0-9]{14}$/}},{$set:{'attributes.invoice_type': 'immediate'}},{multi:1});
db.counters.dropIndex("coll_1_oid_1");

//BRCD-1613 - Configurable VAT label on invoice
var vatLabel = lastConfig['taxation']['vat_label'];
if (!vatLabel) {
	lastConfig['taxation']['vat_label'] = 'VAT';
}

db.config.insert(lastConfig);