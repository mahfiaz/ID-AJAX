<?php

require_once dirname(__FILE__) . '/../conf.php';
require_once dirname(__FILE__) . '/../lib/DigiDoc.class.php';

/***** AUTH ******/

/**
 * Auth
 *
 * Autentimise staatiline klass
 */
class Auth {

    /**
     * Auth.$sid -> Integer
     *
     * Autentimise sessiooni ID
     */
    public static $sid = NULL;

    /**
     * Auth.$stage -> String
     *
     * Kui kaugel autentimine parasjagu on. "authenticated" näitab, et korras, "progress"
     * et tuleb oodata
     */
    public static $stage = NULL;

    /**
     * Auth.$error -> String
     *
     * Tekstilisel kujul veateade
     */
    public static $error = FALSE;

    /**
     * Auth.$error_code -> String
     *
     * Vea kood teksti kujul
     */
    public static $error_code = FALSE;

    /**
     * Auth.$data -> Object
     *
     * Sisselogimise andmed. Tuleb kontrollida, kas Authenticated==TRUE
     */
    public static $data = array();

    /**
     * Auth.AuthStatus() -> Boolean
     *
     * Indikeerib, kas kasutaja on sisse logitud (sessiooni andmed OK) või mitte
     */
    public static function AuthStatus() {
        if (isset($_SESSION['Auth_Data']) and $_SESSION['Auth_Data']) {
            self::$data = unserialize($_SESSION['Auth_Data']);
            // puhverdatud andmed
            if (self::$data["Authenticated"]) {
                self::$stage = "authenticated";
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Auth.Logout() -> undefined
     *
     * Logib kasutaja välja, tühjendades sessiooni
     */
    public static function Logout() {
        self::$sid = NULL;
        self::$stage = NULL;
        self::$error = NULL;
        self::$error_code = NULL;
        self::$data = array();
        unset($_SESSION["Auth_Data"]);
        unset($_SESSION["Sign_Data"]);
    }

    /**
     * Auth.MobileAuthRequest($phone[, $message_to_display= ""][, $lang="EST"]) -> String
     * - $phone (String): telefoni number
     * - $message_to_display (String): lisataede, mis kuvatakse telefoni ekraanil
     * - $lang (String): kasutatav keel, EST, ENG, RUS
     *
     * Kutsub ellu MID autentimise. Edukal autentimise alguse puhul tagastatakse SID,
     * vastasel korral aga FALSE
     */
    public static function MobileAuthRequest($phone, $message_to_display = "", $lang = "EST") {
        $phone = str_replace(" ", "", $phone);
        $phone = trim($phone, "+");

        if (!$message_to_display) $message_to_display = "";
        if (!$lang) $lang = "EST";

        if (!$phone) {
            self::$error = "Invalid phone number";
            self::$error_code = "PHONE_INVALID";
            self::$stage = "error";
            return FALSE;
        }

        if (substr($phone, 0, 3) != "372") {
            $phone = "372" . $phone;
        }

        $dd = new Base_DigiDoc();
        $result = $dd->query('MobileAuthenticate', array(
            'IDCode' => '',
            'Country' => '',
            'PhoneNo' => $phone,
            'Language' => $lang,
            'ServiceName' => DD_SERVICE_NAME,
            'MessageToDisplay' => $message_to_display,
            'SPChallenge' => bin2hex(substr("MYA" . microtime(FALSE), 0, 10)),
            'MessagingMode' =>"asynchClientServer",
            'AsyncConfiguration' => NULL,
            'ReturnCertData' => TRUE,
            'ReturnRevocationData' => FALSE,
        ));
        if ((isset($result) && is_object($result) && is_a($result, 'SOAP_Fault')) || !isset($result["Status"])) {
            // ERROR
            switch ($result->backtrace[0]["args"][0]) {
                case 201:
                case 301:
                    self::$error = "Phone number is not registered in the service!";
                    self::$error_code = "PHONE_UNKNOWN";
                    break;
                case 302:
                    self::$error = "User certificate is revoked or suspended!. <br/>To use Mobile-ID, please turn to your mobile service provider!";
                    self::$error_code = "PHONE_SUSPENDED";
                    break;
                case 303:
                    self::$error = "Mobiil-ID is not activated. To activate, follow URL <a href=\"http://mobiil.id.ee/akt/\">mobiil.id.ee/akt</a>.";
                    self::$error_code = "PHONE_NOT_ACTIVATED";
                    break;
                default:
                    self::$error = "SOAP error";
                    self::$error_code = "PHONE_SOAP_FAULT";
            }
            self::$stage = "error";
            unset($_SESSION["Auth_Data"]);
            return FALSE;
        }
        elseif ($result["Status"] == "OK") {
            // OK
            self::$sid = intval($result["Sesscode"]);
            self::$data = array(
                "SID"           => self::$sid,
                "Authenticated" => FALSE,
                "ChallengeID"   => $result["ChallengeID"],
                "UserIDCode"    => $result["UserIDCode"],
                "UserGivenname" => $result["UserGivenname"],
                "UserSurname"   => $result["UserSurname"],
                "UserCountry"   => $result["UserCountry"],
                "PhoneNumber"   => $phone,
                "UseIDCard"     => FALSE
            );
            self::$stage = "progress";
            $_SESSION["Auth_Data"] = serialize(self::$data);
            return self::$sid;
        }
        elseif (isset($result["Status"]) && $result["Status"] == "NOT_VALID") {
            // ERROR - NOT VALID
            self::$error = "Authentication failed, user certificate is not valid!";
            self::$stage = "error";
            unset($_SESSION["Auth_Data"]);
            return FALSE;
        }
    }

    /**
     * Auth.MobileAuthStatus([$sid = FALSE]) -> Boolean
     * - $sid (Integer): Autentimise sessiooni võti (pärineb MobileAuthRequest päringult)
     *
     * Kontrollib, kaugel autentimine on. Juhul kui tagastati TRUE, siis õnnestus, kõikidel
     * muudel juhtudel on FALSE. Täpsustuseks tuleb kontrollida Auth.stage muutujat, kui see
     * on "progress", siis on ootel. Kui "error", siis viga.
     */
    public static function MobileAuthStatus($sid = FALSE) {
        if ($sid) {
            self::$sid = intval($sid);
        }

        $data = array();
        if ($_SESSION["Auth_Data"]) {
            $data = $_SESSION["Auth_Data"] ? unserialize($_SESSION["Auth_Data"]) : FALSE;
            self::$data = $data;
        }

        // Puhverdatud andmed
        if (self::$data["Authenticated"] && (self::$data["SID"] == $sid || !$sid)) {
            self::$stage = "authenticated";
            return TRUE;
        }

        // ID puudub, sisselogitud polnud
        if (!self::$sid) {
            self::$error = "No session ID";
            self::$error_code = "PHONE_INVALID_SID";
            self::$stage = "error";
            return NULL;
        }

        $dd = new Base_DigiDoc();
        $result = $dd->query('GetMobileAuthenticateStatus', array(self::$sid, FALSE));

        if (is_object($result)) {
            self::$error = $result->userinfo->message;
            self::$error_code = "PHONE_SOAP_FAULT";
            self::$stage = "error";
            return FALSE;
        }

        if (strlen($result["Status"]) > 3) {
            $status = $result["Status"];
        }
        elseif (!isset($result["Status"])) {
            $status = $result->backtrace[0]["args"][0];
        }
        else {
            $status = $result;
        }

        switch ($status) {
            case "USER_AUTHENTICATED":
                self::$stage = "authenticated";
                self::$data = $_SESSION["Auth_Data"] ? unserialize($_SESSION["Auth_Data"]) : array();
                self::$data["Authenticated"] = TRUE;
                $_SESSION["Auth_Data"] = serialize(self::$data);
                return TRUE;
                break;
            case "EXPIRED_TRANSACTION":
                self::$error = "Timeout reached!";
                self::$error_code = "PHONE_EXPIRED_TRANSACTION";
                self::$stage = "error";
                break;
            case "INTERNAL_ERROR":
                self::$error = "Authentication failed: technical error!";
                self::$stage = "error";
                self::$error_code = "PHONE_INTERNAL_ERROR";
                break;
            case "NOT_VALID":
                self::$error = "Authentication failed: generated signature is not valid!";
                self::$stage = "error";
                self::$error_code = "PHONE_NOT_VALID";
                break;
            case "USER_CANCEL":
                self::$error = "User canceled!";
                self::$stage = "error";
                self::$error_code = "PHONE_USER_CANCEL";
                break;
            case "MID_NOT_READY":
                self::$error = "Mobile-ID functionality is not ready yet, please try again after awhile!";
                self::$stage = "error";
                self::$error_code = "PHONE_MID_NOT_READY";
                break;
            case "SIM_ERROR":
                self::$error = "SIM error!";
                self::$stage = "error";
                self::$error_code = "PHONE_SIM_ERROR";
                break;
            case "PHONE_ABSENT":
                self::$error = "Phone is not in coverage area!";
                self::$stage = "error";
                self::$error_code = "PHONE_ABSENT";
                break;
            case "SENDING_ERROR":
                self::$error = "Sending error!";
                self::$stage = "error";
                self::$error_code = "PHONE_SENDING";
                break;
            default:
                self::$stage = "progress";
        }
        return FALSE;
    }


    /**** ID CARD ***/

    public static function CardAuthRequest() {
        $s = getenv('SSL_CLIENT_S_DN');
        if (!trim($s)) {
            $_SESSION["Auth_Data"] = array();
            self::$error = "Invalid Card data";
            self::$error_code = "CARD_INVALID_DATA";
            self::$stage = "error";
            return FALSE;
        }
        $l = preg_split('|/|', $s, -1, PREG_SPLIT_NO_EMPTY);

        $result = array();
        foreach($l as $e) {
            list($n, $v) = explode('=', $e, 2);
            $result[$n] = self::certstr2utf8($v);
        }

        self::$stage = "authenticated";
        self::$data = array(
            "Authenticated" => TRUE,
            "UserIDCode"    => $result["serialNumber"],
            "UserGivenname" => $result["GN"],
            "UserSurname"   => $result["SN"],
            "UserCountry"   => $result["C"],
            "PhoneNumber"   => FALSE,
            "UseIDCard"     => TRUE);

        $_SESSION["Auth_Data"] = serialize(self::$data);
        return TRUE;
    }

    public static function certstr2utf8($str) {
        $str = preg_replace("/\\\\x([0-9ABCDEF]{1,2})/e", "chr(hexdec('\\1'))", $str);

        $encoding = mb_detect_encoding($str, "ASCII, UCS2, UTF8");

        if ($encoding == "ASCII") {
            $result = mb_convert_encoding($str, "UTF-8", "ASCII");
        }
        elseif (substr_count($str, chr(0)) > 0) {
            $result = mb_convert_encoding($str, "UTF-8", "UCS2");
        }
        else {
            $result = $str;
        }
        return $result;
    }
}


/***** SIGN *****/

class Sign {

    public static $sid = FALSE;
    public static $stage = FALSE;
    public static $error = FALSE;
    public static $error_code = FALSE;
    public static $data = array();
    public static $files = array();

    public static $template = "<DataFile xmlns=\"http://www.sk.ee/DigiDoc/v1.3.0#\" ContentType=\"EMBEDDED_BASE64\" Filename=\"%s\" Id=\"%s\" MimeType=\"%s\" Size=\"%s\">%s\n</DataFile>";

    public static function addFile($contents, $fname = "test.txt", $mime = FALSE) {
        $id = "D" . count(self::$files);
        $fname = $fname;
        if (!$mime) {
            $mime = self::mime_content_type($fname);
        }
        $fname = trim(htmlspecialchars($fname));
        $mime = trim(htmlspecialchars($mime));
        $size = strlen($contents);
        $fdata = sprintf(self::$template, $fname, $id, $mime, $size, base64_encode($contents));

		      self::$files[] = array(
			         "id"=>$id,
			         "fileName"=>$fname,
			         "mimeType"=>$mime,
			         "size"=>$size,
			         "dataFile"=>$fdata,
			         "digestValue"=>base64_encode(pack("H*", sha1($fdata)))
		      );
    }

    public static function generateDDOC($signatures = FALSE) {
        if ($signatures !== FALSE) {
            self::$data["Signed"] = TRUE;
            self::$data["Signature"] = join($signatures, "");
            if (!self::$data["Signature"]) {
                self::$data["Signature"] = "";
            }
        }
        if (!self::$data["Signed"] || !count(self::$files)) {
            return FALSE;
        }
        $ddoc_contents = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $ddoc_contents.= '<SignedDoc format="DIGIDOC-XML" version="1.3" xmlns="http://www.sk.ee/DigiDoc/v1.3.0#">' . "\n";
        for ($i = 0;$i < 1 && $i < count(self::$files);$i++) {
            $ddoc_contents.= self::$files[$i]["dataFile"] . "\n";
        }
        $ddoc_contents.= self::$data["Signature"];
        $ddoc_contents.= "</SignedDoc>";
        return $ddoc_contents;
    }

    public static function downloadDDOC($fname = FALSE) {
        if (!$fname) {
            $fname = "container";
        }
        $ddoc_contents = self::generateDDOC();
        if (!$ddoc_contents) {
            header("Content-type: text/html; Charset=utf-8");
            echo "<h1>Error</h1><p>Invalid or expired data</p>";
            exit;
        }
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-Disposition: attachment; filename=$fname.ddoc");
        header("Content-Type: application/x-ddoc");
        header("Content-Transfer-Encoding: binary");
        echo $ddoc_contents;
        exit;
    }

    public static function CardPrepareSignature($fid, $certId, $certHex) {
        $dd = new Base_DigiDoc();
        $ddoc = new Parser_DigiDoc();

        $file = FileStore::retrieve($fid);
        if (!$file) {
            self::$error = "Unknown file";
            self::$error_code = "FILE_INVALID";
            self::$stage = "error";
            return FALSE;
        }

        if (count($file["signatures"])) {
            $existing_ddoc = FileStore::generateDDOC($fid);

            $ret = $dd->query('startSession', array(
                'SigningProfile' => '',
                'SigDocXML' => $existing_ddoc["signedContents"],
                'bHoldSession' => TRUE,
                'Datafile' => '',
            ));
        }
        else {
            $f['Filename'] = $file["fileName"];
            $f['MimeType'] = $file["mimeType"];
            $f['ContentType'] = 'EMBEDDED_BASE64';
            $f['Size'] = strlen($file["contents"]);
            $f['DfData'] = chunk_split(base64_encode($file["contents"]), 64, "\n");

            $datafile = new SOAP_Value('datafile', DD_WSDL_DATAFILE, $f);

            $ret = $dd->query('startSession', array(
                'SigningProfile' => '',
                'SigDocXML' => '',
                'bHoldSession' => TRUE,
                'Datafile' => $f,
            ));
        }

        //print_r($ret);
        //print_r(FileStore::generateDDOC($fid));

        if (!PEAR::isError($ret) || $ret["Status"] != "OK") {
            $result = $ddoc->Parse($dd->Client->xml, 'body');
            self::$sid = intval($result["Sesscode"]);

            $signatureData = $dd->query('PrepareSignature', array(
                'Sesscode' => intval(self::$sid),
                'SignersCertificate' => $certHex,
                'SignersTokenId' => $certId,
                'Role' => stripslashes($_REQUEST['Role']),
                'City' => stripslashes($_REQUEST['City']),
                'State' => stripslashes($_REQUEST['State']),
                'PostalCode' => stripslashes($_REQUEST['PostalCode']),
                'CountryName' => stripslashes($_REQUEST['Country'])
            ));

            self::$data = array(
                "SID"           => self::$sid,
                "FID"           => $fid,
                "Signed"        => FALSE,
                "Signature"     => "",
                "signatureRequest" => $signatureData["SignedInfoDigest"],
                "signatureId" => $signatureData["SignatureId"]
            );
            self::$stage="prepared";
            $_SESSION["Sign_Data"] = serialize(self::$data);
            return self::$sid;
        }
        else {
            self::$error = $ret->getMessage() ? $ret->getMessage() : "Error creating session";
            self::$error_code = "CARD_SESSION";
            self::$stage = "error";
            unset($_SESSION["Sign_Data"]);
            return FALSE;
        }
    }

    public static function CardFinalizeSignature($signatureId, $signatureHex) {
        $dd = new Base_DigiDoc();
        $ddoc = new Parser_DigiDoc();
        $data = array();
        if ($_SESSION["Sign_Data"]) {
            $data = $_SESSION["Sign_Data"] ? unserialize($_SESSION["Sign_Data"]) : FALSE;
            self::$data = $data;
        }

        if (!self::$data['SID']) {
            self::$error = "Unknown session";
            self::$error_code = "SESSION_INVALID";
            self::$stage = "error";
            unset($_SESSION["Sign_Data"]);
            return FALSE;
        }

        $ret = $dd->query('FinalizeSignature', array(
            'Sesscode' => intval(self::$data['SID']),
            'SignatureId' => $signatureId,
            'SignatureValue' => $signatureHex,
        ));

        if (!PEAR::isError($ret) || $ret["Status"] != "OK") {
            $signatureData = array();
            $signatureData["SignedTime"] = strtotime($ret["SignedDocInfo"]->SignatureInfo->SigningTime);
            $signatureData["UserSurname"] = self::$data["UserSurname"];
            $signatureData["UserGivenname"] = self::$data["UserGivenname"];
            $signatureData["UserIDCode"] = self::$data["UserIDCode"];
            $signature = FALSE;
            $ret = $dd->query('GetSignedDoc', array(
                'Sesscode' => intval(self::$data['SID']),
            ));

            if (!PEAR::isError($ret) || $ret["Status"] != "OK") {
                $ddoc = new Parser_DigiDoc($ret['SignedDocData']);
                if (preg_match("/<Signature Id=\"" . $signatureId . "\"(.*?)<\/Signature>/s", $ddoc->getDigiDoc(), $m)) {
                    $signature = trim($m[0]);
                }
                if (!$signature) {
                    self::$error = $ret->getMessage() ? $ret->getMessage() : "Error creating signature";
                    self::$error_code = "CARD_SIGNATURE";
                    self::$stage = "error";
                    unset($_SESSION["Sign_Data"]);
                    return FALSE;
                }
            }
            else {
                self::$error = $ret->getMessage() ? $ret->getMessage() : "Error creating signature";
                self::$error_code = "CARD_SIGNATURE";
                self::$stage = "error";
                unset($_SESSION["Sign_Data"]);
                return FALSE;
            }

            self::$data["Signed"] = TRUE;
            self::$data["Signature"] = $signature;
            $_SESSION["Sign_Data"] = serialize(self::$data);
            return TRUE;
        }
        else {
            self::$error = $ret->getMessage() ? $ret->getMessage() : "Error creating signature";
            self::$error_code = "CARD_SESSION";
            self::$stage = "error";
            unset($_SESSION["Sign_Data"]);
            return FALSE;
        }
    }

    public static function MobileSignRequest($phone, $fid, $message = "", $lang = "EST", $signature_number = 0) {
        $phone = str_replace(" ", "", $phone);
        $phone = trim($phone, "+");
        if (!$phone) {
            self::$error = "Invalid phone number";
            self::$error_code = "PHONE_INVALID";
            self::$stage = "error";
            return FALSE;
        }
        if (substr($phone, 0, 3) != "372") {
            $phone = "372" . $phone;
        }

        $digests = array();
        $DataFileDigests = new stdClass;
        for ($i = 0;$i < 1 && $i < count(self::$files);$i++) {
            $DataFileDigestInfo = new stdClass;
            $DataFileDigestInfo->Id = self::$files[$i]["id"];
            $DataFileDigestInfo->DigestType = "sha1";
            $DataFileDigestInfo->DigestValue = self::$files[$i]["digestValue"];
            $DataFileDigests->DataFileDigest = $DataFileDigestInfo;
        }

        if (!$lang) $lang = "EST";
        if (!$message) $message = "";

        $dd = new Base_DigiDoc();
        $result = $dd->query('MobileCreateSignature', array(
            'IDCode' => '',
            'Country' => 'EE',
            'PhoneNo' => $phone,
            'Language' => $lang,
            'ServiceName' => DD_SERVICE_NAME,
            'MessageToDisplay' => '',
            'Role' => '',
            'City' => '',
            'StateOrProvince' => '',
            'PostalCode' => '',
            'CountryName' => '',
            'SigningProfile' => '',
            'Datafiles' => $DataFileDigests,
            'Format' => "DIGIDOC-XML",
            'Version' => "1.3",
            'SignatureID' => "S" . $signature_number,
            'MessagingMode' => "asynchClientServer",
            'AsyncConfiguration' => NULL,
        ));

        if ((isset($result) && is_object($result) && is_a($result, 'SOAP_Fault')) || !isset($result["Status"])) {
            switch ($result->backtrace[0]["args"][0]) {
                case 201:
                case 301:
                    self::$error = "Phone number is not registered in the service!";
                    self::$error_code = "PHONE_UNKNOWN";
                    break;
                case 302:
                    self::$error = "User certificate is revoked or suspended!. <br/>To use Mobile-ID, please turn to your mobile service provider!";
                    self::$error_code = "PHONE_SUSPENDED";
                    break;
                case 303:
                    self::$error = "Mobiil-ID is not activated. To activate, follow URL <A HREF=\"http://mobiil.id.ee/akt/\">mobiil.id.ee/akt</A>.";
                    self::$error_code = "PHONE_NOT_ACTIVATED";
                    break;
                default:
                    self::$error = "SOAP error";
                    self::$error_code = "PHONE_SOAP_FAULT";
            }
            self::$stage = "error";
            unset($_SESSION["Sign_Data"]);
            return FALSE;
        }
        elseif ($result["Status"] == "OK") {
            // OK
            self::$sid = intval($result["Sesscode"]);

            self::$data = array(
                "SID"           => self::$sid,
                "FID"           => $fid,
                "Signed"        => FALSE,
                "Signature"	    => "",
                "ChallengeID"   => $result["ChallengeID"]
            );
            self::$stage = "progress";
            $_SESSION["Sign_Data"] = serialize(self::$data);
            return self::$sid;
        }
    }

    public static function MobileSignStatus($sid = FALSE) {
        if ($sid) {
            self::$sid = intval($sid);
        }
        $data = array();
        if ($_SESSION["Sign_Data"]) {
            $data = $_SESSION["Sign_Data"] ? unserialize($_SESSION["Sign_Data"]) : FALSE;
            self::$data = $data;
        }
        // puhverdatud andmed
        if (self::$data["Signed"] && (self::$data["SID"] == $sid || !$sid)) {
            self::$stage = "signed_cached";
            return TRUE;
        }
        // ID puudub, sisselogitud polnud
        if (!self::$sid) {
            self::$error = "No session ID";
            self::$error_code = "PHONE_INVALID_SID";
            self::$stage = "error";
            return NULL;
        }

        $dd = new Base_DigiDoc();
        $result = $dd->query('GetMobileCreateSignatureStatus', array(
            'Sesscode' => self::$sid,
            'WaitSignature' => FALSE
        ));

        if (is_object($result)) {
            self::$error = $result->userinfo->message;
            self::$error_code = "PHONE_SOAP_FAULT";
            self::$stage = "error";
            return FALSE;
        }
        if (strlen($result["Status"]) > 3) {
            $status = $result["Status"];
        }
        elseif (!isset($result["Status"])) {
            $status = $result->backtrace[0]["args"][0];
        }
        else {
            $status = $result;
        }
        switch ($status) {
            case "SIGNATURE":
                self::$stage = "signed";
                self::$data = $_SESSION["Sign_Data"] ? unserialize($_SESSION["Sign_Data"]) : array();
                self::$data["Signed"] = TRUE;
                self::$data["Signature"] = $result["Signature"];
                $_SESSION["Sign_Data"] = serialize(self::$data);
                return TRUE;
            case "EXPIRED_TRANSACTION":
                self::$error = "Timeout reached!";
                self::$error_code = "PHONE_EXPIRED_TRANSACTION";
                self::$stage = "error";
                break;
            case "INTERNAL_ERROR":
                self::$error = "Signing failed: technical error!";
                self::$stage = "error";
                self::$error_code = "PHONE_INTERNAL_ERROR";
                break;
            case "NOT_VALID":
                self::$error = "Signing failed: generated signature is not valid!";
                self::$stage = "error";
                self::$error_code = "PHONE_NOT_VALID";
                break;
            case "USER_CANCEL":
                self::$error = "User canceled!";
                self::$stage = "error";
                self::$error_code = "PHONE_USER_CANCEL";
                break;
            case "MID_NOT_READY":
                self::$error = "Mobile-ID functionality is not ready yet, please try again after awhile!";
                self::$stage = "error";
                self::$error_code = "PHONE_MID_NOT_READY";
                break;
            case "SIM_ERROR":
                self::$error = "SIM error!";
                self::$stage = "error";
                self::$error_code = "PHONE_SIM_ERROR";
                break;
            case "PHONE_ABSENT":
                self::$error = "Phone is not in coverage area!";
                self::$stage = "error";
                self::$error_code = "PHONE_ABSENT";
                break;
            case "SENDING_ERROR":
                self::$error = "Sending error!";
                self::$stage = "error";
                self::$error_code = "PHONE_SENDING";
                break;
            default:
                self::$stage = "progress";
        }
        return FALSE;
    }

    public static function mime_content_type($filename) {
        $mime_types = array(
            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'swf' => 'application/x-shockwave-flash',
            'flv' => 'video/x-flv',

            // images
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml',

            // archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'exe' => 'application/x-msdownload',
            'msi' => 'application/x-msdownload',
            'cab' => 'application/vnd.ms-cab-compressed',

            // audio/video
            'mp3' => 'audio/mpeg',
            'qt' => 'video/quicktime',
            'mov' => 'video/quicktime',

            // adobe
            'pdf' => 'application/pdf',
            'psd' => 'image/vnd.adobe.photoshop',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'ps' => 'application/postscript',

            // ms office
            'doc' => 'application/msword',
            'docx' => 'application/msword',
            'rtf' => 'application/rtf',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.ms-powerpoint',

            // open office
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (array_key_exists($ext, $mime_types)) {
            return $mime_types[$ext];
        }
        else {
            return 'application/octet-stream';
        }
    }

    public static function parseSignature($signature) {
        $signatureData = array();
        preg_match("/\<X509Certificate\>(.*?)\<\/X509Certificate\>/s", $signature, $m);
        if ($m && $m[1]) {
            $userData = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n" . $m[1] . "\n-----END CERTIFICATE-----");
            $signatureData["UserSurname"] = $userData["subject"]["SN"];
            $signatureData["UserGivenname"] = $userData["subject"]["GN"];
            $signatureData["UserIDCode"] = $userData["subject"]["serialNumber"];
        }
        preg_match("/\<SigningTime\>(.*?)\<\/SigningTime\>/s", $signature, $m);
        if ($m && $m[1]) {
            $signatureData["SignedTime"] = strtotime($m[1]);
        }
        return $signatureData;
    }
}

