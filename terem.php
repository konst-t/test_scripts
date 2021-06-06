<?
use \Bitrix\Main\Loader;

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
set_time_limit(0);

if (!Loader::includeModule('iblock')) {
	die('Module iblock not included');
}

/*
If in future we will add prices and etc.

if (!Loader::includeModule('catalog')) {
	die('Module catalog not included');
}

if (!Loader::includeModule('sale')) {
	die('Module sale not included');
}*/

if (!file_exists('reference.xml')) {
	die('File reference.xml not found');
}

if (!file_exists('goods.xml')) {
	die('File goods.xml not found');
}


/** SETTINGS */
define("IBLOCK_ID", 1);



/**
	 * XML parsing
	 *
	 * @param string $file File name
	 * @return array
*/
function getXML( $file ) {
	$xml = simplexml_load_file( $file );
	if (!$xml) {
		die('Unable to pars file '.$file);
	}
	$json_string = json_encode( $xml );
	unset($xml);
	return json_decode( $json_string, TRUE );
}


/**
	 * Create symbol code from name
	 *
	 * @param string $name Property name
	 * @return string
*/
function getCode( $name ) {
	$arParams = [
		"change_case" => "U"
	];
	$sTranslit = \Cutil::translit($name,"ru",$arParams);
	return "PROP_".$sTranslit;
}


/* Properties and values parsing from XML */

$arProps = [];
$arPropValues = [];
$arType = [
	"Ссылка" => "L",
	"Строка" => "S",
];

$arPropXml = getXML('reference.xml');

foreach($arPropXml['Характеристики']['Характеристика'] as $prop) {
	$multiple = ($prop['@attributes']['Тип'] == "Ссылка" ? "Y" : "N");
	$arProps[] = [
		"XML_ID"        => $prop['@attributes']['ExtID'],
		"NAME"          => $prop['@attributes']['Наименование'],
		"PROPERTY_TYPE" => $arType[$prop['@attributes']['Тип']],
		"MULTIPLE"      => $multiple,
	];
}
foreach($arPropXml['ЗначенияХарактеристик']['Значение'] as $value) {
	$arPropValues[] = [
		"XML_ID"        => $value['@attributes']['ExtID'],
		"VALUE"         => $value['@attributes']['Наименование'],
		"PROP"          => $value['@attributes']['ХарактеристикаExtID'],
	];
}
unset($arPropXml);


/* Get existing properies and values */

$arExistingProps = [];
$arExistingValues = [];
$res = \CIBlock::GetProperties(IBLOCK_ID);
while($prop = $res->Fetch()){
	$arExistingProps[$prop["XML_ID"]] = ["ID"=>$prop["ID"],"CODE"=>$prop["CODE"]];
	if($prop["PROPERTY_TYPE"] == "L") {
		$enum_res = \CIBlockProperty::GetPropertyEnum($prop["ID"], [], ["IBLOCK_ID"=>IBLOCK_ID]);
		while($arValue = $enum_res->GetNext()) {
			$arExistingValues[$arValue["XML_ID"]] = $arValue;
		}
	}
}
unset($res);
unset($enum_res);



/* Create or update properties */

$ibp = new \CIBlockProperty;

$arCommonFields = [
	"IBLOCK_ID" => IBLOCK_ID,
	"ACTIVE" => "Y",
	"SORT" => "100",

];

foreach($arProps as $key => $arFields) {
	$arFields = array_merge($arFields, $arCommonFields);
	if(array_key_exists($arFields["XML_ID"], $arExistingProps)) { 
		$arFields["CODE"] = $arExistingProps[$arFields["XML_ID"]]["CODE"];
		if(!$ibp->Update($arExistingProps[$arFields["XML_ID"]]["ID"], $arFields))
			echo $ibp->LAST_ERROR."<br>";
	}
	else {
		$arFields["CODE"] = getCode($arFields["NAME"]);
		if (!$PropertyID = $ibp->Add($arFields)) {
			echo $ibp->LAST_ERROR."<br>";
		}
		else {
			$arExistingProps[$arFields["XML_ID"]] = ["ID"=>$PropertyID,"CODE"=>$arFields["CODE"]];
		}
	}
	unset($arProps[$key]);
}


/* Create or update values */

$ibpenum = new \CIBlockPropertyEnum;

foreach($arPropValues as $key => $arFields) {
	if(array_key_exists($arFields["XML_ID"], $arExistingValues)) {
		$ibpenum->Update($arExistingValues[$arFields["XML_ID"]]["ID"], ['VALUE'=>$arFields["VALUE"]]);
		$arExistingValues[$arFields["XML_ID"]] = ["ID" => $arExistingValues[$arFields["XML_ID"]]["ID"]];

	}
	else {
		$arFields["PROPERTY_ID"] = $arExistingProps[$arFields["PROP"]]["ID"];
		if ($arFields["PROPERTY_ID"] < 1)
			continue;
		if($PropID = $ibpenum->Add($arFields)) {
			echo $ibp->LAST_ERROR."<br>";
		}
		else {
			$arExistingValues[$arFields["XML_ID"]] = ["ID" => $PropID];
		}
	}
	unset($arPropValues[$key]);
}



/* Get existing products */

$obElement = new \CIBlockElement;

$rsItems = \CIBlockElement::GetList(
	[],
	['IBLOCK_ID' => IBLOCK_ID],
	false,
	false,
	['ID', 'IBLOCK_ID', 'XML_ID']
);

$arExistingProducts = [];

while ($obItem = $rsItems->GetNextElement()) {
	$arItem = $obItem->GetFields();
	$arExistingProducts[$arItem['XML_ID']] = $arItem['ID'];
}



/* Products parsing from XML and add/update in catalog */

$arProducts = [];

$arProdXml = getXML('goods.xml'); 

foreach($arProdXml['ТМЦ'] as $key => $prod) {
	$arFields = [];
	$arFields["XML_ID"]     = $prod['@attributes']['ExtID'];
	$arFields["NAME"]       = $prod['@attributes']['НаименованиеДляСайта'];
	$arFields["IBLOCK_ID"]  = IBLOCK_ID;
	$arFields["PROPERTY_VALUES"] = [];
	foreach($prod["ХарактеристикиСсылки"]["ХарактеристикаСсылка"] as $property) {
		$value = [];
		$arExtValIds = explode(",", $property['@attributes']['ЗначениеExtID']);
		foreach($arExtValIds as $_v) {
			$_v = trim($_v);
			if ($_v != "" && $arExistingValues[$_v]["ID"] > 0) {
				$value[] = $arExistingValues[$_v]["ID"];
			}
		}
		if (array_key_exists($property['@attributes']['ExtID'], $arExistingProps) && count($value)){
			$arFields["PROPERTY_VALUES"][$arExistingProps[$property['@attributes']['ExtID']]["CODE"]] = $value;
		}
	}
	foreach($prod["ХарактеристикиСтроки"]["ХарактеристикаСтрока"] as $property) {
		if (array_key_exists($property['@attributes']['ExtID'], $arExistingProps) && $property['@attributes']['Значение'] != ""){
			$arFields["PROPERTY_VALUES"][$arExistingProps[$property['@attributes']['ExtID']]["CODE"]] = $property['@attributes']['Значение'];
		}
	}

	if(array_key_exists($arFields["XML_ID"], $arExistingProducts)) {
		if(!$obElement->Update($arExistingProducts[$arFields["XML_ID"]]["ID"],$arFields))
			echo "Error: ".$obElement->LAST_ERROR."<br>";
	}
	else {
		if(!$PRODUCT_ID = $obElement->Add($arFields))
			echo "Error: ".$obElement->LAST_ERROR."<br>";
	}
	unset($arProdXml['ТМЦ'][$key]);
}

?>