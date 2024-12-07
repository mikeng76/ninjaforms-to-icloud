<?php
class ICloudCardDAV {
    private $baseUrl = 'https://contacts.icloud.com';
    private $principalUrl = 'https://contacts.icloud.com/principals/';
    private $username;
    private $password;
    private $addressBookUrl;

    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Discover address book with multiple authentication checks
     */
    public function discoverAddressBook() {
        // Try different authentication and discovery methods
        $discoveryMethods = [
            [$this, 'discoverWithCardDavPath'],
            [$this, 'discoverWithPrincipalPath']
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
        $url = $this->baseUrl . '/card/dav/';

        $ch = curl_init($url);
        // SSL Configuration
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Depth: 1',
            'Content-Type: application/xml; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);

        curl_close($ch);

        if ($errorNo !== 0) {
            throw new Exception("cURL Error: $errorMsg");
        }

        if ($httpCode == 207) {
            $this->addressBookUrl = '/card/dav/';
            return $this->addressBookUrl;
        }

        throw new Exception("CardDAV Path Discovery Failed. HTTP Code: $httpCode");
    }

    /**
     * Attempt discovery with principal path
     */
    private function discoverWithPrincipalPath() {
        $ch = curl_init($this->principalUrl);
        // SSL Configuration
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Depth: 1',
            'Content-Type: application/xml; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);

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
}

// Usage Example
try {
    // IMPORTANT: Use an APP-SPECIFIC PASSWORD, not your main iCloud password
    //$cardDav = new ICloudCardDAV('your_icloud_email@icloud.com', 'your_app_specific_password');
    $cardDav = new ICloudCardDAV('smartriver@icloud.com', 'gioy-zpeq-cals-kwuf');

    // Validate credentials first
    if (!$cardDav->validateCredentials()) {
        throw new Exception("Invalid iCloud credentials or authentication failed");
    }

    // Fetch contacts
    $contacts = $cardDav->fetchContacts();
    print_r($contacts);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";

    // Detailed troubleshooting guidance
    echo "\nTroubleshooting Tips:\n";
    echo "1. Ensure you're using an APP-SPECIFIC PASSWORD, not your main iCloud password\n";
    echo "2. Verify two-factor authentication is enabled\n";
    echo "3. Check that you have enabled CardDAV in iCloud settings\n";
    echo "4. Confirm your network allows external API calls\n";
}