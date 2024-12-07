<?php
// Prevent direct access
if (!defined('ABSPATH')) exit;

class ICloudCardDAV {
    private $baseUrl = 'https://contacts.icloud.com';
    private $principalUrl = '';
    private $username;
    private $password;
    private $addressBookUrl;
    private $certificatePath;

    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;

        // Try to locate a valid CA bundle
        $possibleCertPaths = [
            // Common locations
            '/etc/ssl/certs/ca-certificates.crt',      // Ubuntu/Debian
            '/etc/pki/tls/certs/ca-bundle.crt',        // CentOS/RHEL
            '/etc/ssl/ca-bundle.pem',                 // OpenSUSE
            '/usr/local/etc/openssl/cert.pem',        // macOS Homebrew
            __DIR__ . '/cacert.pem'                   // Local directory
        ];

        // Find the first existing certificate file
        foreach ($possibleCertPaths as $path) {
            if (file_exists($path)) {
                $this->certificatePath = $path;
                break;
            }
        }

        // If no certificate found, download latest
        if (!$this->certificatePath) {
            $this->downloadLatestCertBundle();
        }

    }

    /**
     * Download the latest CA certificate bundle
     */
    private function downloadLatestCertBundle() {
        $certUrl = 'https://curl.se/ca/cacert.pem';
        $this->certificatePath = __DIR__ . '/cacert.pem';

        // Download certificate bundle
        $certData = file_get_contents($certUrl);
        if ($certData) {
            file_put_contents($this->certificatePath, $certData);
        } else {
            throw new Exception("Could not download CA certificate bundle");
        }
    }

    /**
     * Create cURL handle with proper SSL configuration
     */
    private function createCurlHandle($url, $customRequest = 'GET') {
        $ch = curl_init($url);

        // SSL Configuration
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Use the located or downloaded CA bundle
        if ($this->certificatePath) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->certificatePath);
        }

        // Authentication
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);

        // Common cURL options
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequest);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, 'CURL_HTTP_VERSION_1_1');

        // Debugging
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        return ['handle' => $ch, 'verbose' => $verbose];
    }

    /**
     * Discover address book with multiple authentication checks
     */
    public function discoverAddressBook() {
        // Try different authentication and discovery methods
        $discoveryMethods = [
            [$this, 'discoverWithCardDavPath']
            //[$this, 'discoverWithPrincipalPath']
        ];

        foreach ($discoveryMethods as $method) {
            try {
                return $method();
            } catch (Exception $e) {
                // Log the specific failure, but continue to next method
                error_log("Discovery method failed: " . $e->getMessage());
            }
        }

        throw new Exception("Unable to discover iCloud address book. Please check your credentials and configuration.");
    }

    /**
     * Attempt discovery with standard CardDAV path
     */
    private function discoverWithCardDavPath() {

        $url = $this->baseUrl;
        $curlSetup = $this->createCurlHandle($url, 'PROPFIND');
        $ch = $curlSetup['handle'];
        $verbose = $curlSetup['verbose'];

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/xml',
            'Depth: 0',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '<propfind xmlns=\'DAV:\'><prop><current-user-principal/></prop></propfind>');

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Capture verbose log
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);

        curl_close($ch);

        if ($errorNo !== 0) {
            throw new Exception("cURL Error: $errorMsg");
        }

        if ($httpCode == 207) {

            // Parse XML response to find address book URL
            $xml = simplexml_load_string($response);
            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $principalUrl = $xml->xpath('//d:response/d:propstat/d:prop/d:current-user-principal/d:href');

            if (!empty($principalUrl)) {

                $this->principalUrl = (string)$principalUrl[0];

                $curlSetup = $this->createCurlHandle($this->baseUrl . $this->principalUrl, 'PROPFIND');
                $ch = $curlSetup['handle'];
                $verbose = $curlSetup['verbose'];

                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: text/xml',
                    'Depth: 1',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, '<d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav"><d:prop><card:addressbook-home-set/></d:prop></d:propfind>');

                $response = curl_exec($ch);
                $errorNo = curl_errno($ch);
                $errorMsg = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                // Capture verbose log
                rewind($verbose);
                $verboseLog = stream_get_contents($verbose);

                curl_close($ch);

                if ($errorNo !== 0) {
                    throw new Exception("cURL Error: $errorMsg");
                }

                if ($httpCode == 207) {

                    // Parse XML response to find address book URL
                    $xml = simplexml_load_string($response);
                    $xml->registerXPathNamespace('d', 'DAV:');
                    $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');

                    $addressBookPath = $xml->xpath('//d:response/d:propstat/d:prop/card:addressbook-home-set/d:href');

                    if (!empty($addressBookPath)) {
                        $this->addressBookUrl = (string)$addressBookPath[0];
                        return $this->addressBookUrl;
                    }

                }

            }

        }

        throw new Exception("CardDAV Path Discovery Failed. HTTP Code: $httpCode");
    }

    /**
     * Attempt discovery with principal path
     */
    private function discoverWithPrincipalPath() {

        $curlSetup = $this->createCurlHandle($this->principalUrl, 'PROPFIND');
        $ch = $curlSetup['handle'];
        $verbose = $curlSetup['verbose'];

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Capture verbose log
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);

        curl_close($ch);

        if ($errorNo !== 0) {
            throw new Exception("cURL Error: $errorMsg");
        }

        if ($httpCode == 207) {
            // Parse XML to find exact address book URL
            $xml = simplexml_load_string($response);
            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $addressBookPath = $xml->xpath('//d:href[contains(., "/card/")]');

            if (!empty($addressBookPath)) {
                $this->addressBookUrl = (string)$addressBookPath[0];
                return $this->addressBookUrl;
            }
        }

        throw new Exception("Principal Path Discovery Failed. HTTP Code: $httpCode");
    }

    /**
     * Validate Credentials
     */
    public function validateCredentials() {
        try {
            $this->discoverAddressBook();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Fetch Contacts
     */
    public function fetchContacts() {
        if (!$this->addressBookUrl) {
            $this->discoverAddressBook();
        }

        $url = $this->baseUrl . $this->addressBookUrl;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'REPORT');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Depth: 1',
            'Content-Type: application/xml; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '<?xml version="1.0" encoding="UTF-8"?>
            <card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
                <d:prop>
                    <d:getetag/>
                    <card:address-data/>
                </d:prop>
            </card:addressbook-query>');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode == 207) {
            return $this->parseContacts($response);
        }

        throw new Exception("Fetch Contacts Failed. HTTP Code: $httpCode");
    }

    /**
     * Parse Contacts from XML Response
     */
    private function parseContacts($xmlResponse) {
        $xml = simplexml_load_string($xmlResponse);
        $contacts = [];

        if ($xml === false) {
            throw new Exception("Failed to parse XML response");
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');

        $responses = $xml->xpath('//d:response');
        foreach ($responses as $response) {
            $addressData = $response->xpath('.//card:address-data');
            if (!empty($addressData)) {
                $contacts[] = (string)$addressData[0];
            }
        }

        return $contacts;
    }

    /**
     * Create a new contact with error handling
     */
    public function createContact($vcard, $uid)
    {
        if (!$this->addressBookUrl) {
            $this->discoverAddressBook();
        }

        // Generate a unique filename for the contact
        $filename = $uid . '.vcf';
        $contactUrl = $this->addressBookUrl . 'card/' . $filename;

        $curlSetup = $this->createCurlHandle($contactUrl, 'PUT');
        $ch = $curlSetup['handle'];
        $verbose = $curlSetup['verbose'];

        curl_setopt($ch, CURLOPT_POSTFIELDS, $vcard);

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Capture verbose log
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);

        curl_close($ch);

        if ($errorNo !== 0) {
            throw new Exception("cURL Error: $errorMsg");
        }

        if ($httpCode == 201) {
            return true;
        }

        throw new Exception("Failed to create contact. HTTP Code: $httpCode, Response: $response");

    }

}
