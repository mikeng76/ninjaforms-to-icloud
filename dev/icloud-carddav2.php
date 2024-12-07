<?php

class ICloudCardDAV
{
    // Specific iCloud CardDAV endpoint for contacts
    private $baseUrl = 'https://contacts.icloud.com';
    private $username;
    private $password;
    private $addressBookUrl;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Discover or set the address book URL
     */
    public function discoverAddressBook()
    {
        // Known iCloud CardDAV path for contacts
        $this->addressBookUrl = '/card/dav/';

        // Verify the endpoint
        $ch = curl_init($this->baseUrl . $this->addressBookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Depth: 1',
            'Content-Type: application/xml; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable verbose output for debugging
        curl_setopt($ch, CURLOPT_FAILONERROR, false);

        // Capture verbose output
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Rewind and read verbose output
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);

        curl_close($ch);

        // Detailed error logging
        if ($errorNo !== 0) {
            throw new Exception("cURL Error ($errorNo): $errorMsg\nVerbose Info: $verboseLog");
        }

        // Check HTTP response
        if ($httpCode == 207) {
            return $this->addressBookUrl;
        }

        // Throw exception with more context
        throw new Exception("Failed to discover address book. HTTP Code: $httpCode, Response: $response\nVerbose Log: $verboseLog");
    }

    /**
     * Fetch contacts with comprehensive error handling
     */
    public function fetchContacts()
    {
        if (!$this->addressBookUrl) {
            $this->discoverAddressBook();
        }

        $ch = curl_init($this->baseUrl . $this->addressBookUrl);
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
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // Comprehensive error checking
        if ($errorNo !== 0) {
            throw new Exception("cURL Error ($errorNo): $errorMsg");
        }

        if ($httpCode == 207) {
            return $this->parseContactResponse($response);
        }

        throw new Exception("Failed to fetch contacts. HTTP Code: $httpCode, Response: $response");
    }

    /**
     * Create a new contact with error handling
     */
    public function createContact($vcard)
    {
        if (!$this->addressBookUrl) {
            $this->discoverAddressBook();
        }

        // Generate a unique filename for the contact
        $filename = uniqid() . '.vcf';
        $contactUrl = $this->baseUrl . $this->addressBookUrl . $filename;

        $ch = curl_init($contactUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/vcard; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $vcard);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errorNo !== 0) {
            throw new Exception("cURL Error ($errorNo): $errorMsg");
        }

        if ($httpCode == 201) {
            return true;
        }

        throw new Exception("Failed to create contact. HTTP Code: $httpCode, Response: $response");
    }

    /**
     * Parse contact response from CardDAV server
     */
    private function parseContactResponse($xmlResponse)
    {
        $xml = simplexml_load_string($xmlResponse);
        if ($xml === false) {
            throw new Exception("Failed to parse XML response");
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');

        $contacts = [];
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

// Example usage with extensive error handling
try {
    // Use your iCloud email and an app-specific password
    //$cardDav = new ICloudCardDAV('your_icloud_email@icloud.com', 'your_app_specific_password');
    $cardDav = new ICloudCardDAV('smartriver@icloud.com', 'gioy-zpeq-cals-kwuf');

    // Discover address book with more detailed error reporting
    try {
        $addressBookUrl = $cardDav->discoverAddressBook();
        echo "Address Book URL: " . $addressBookUrl . "\n";
    } catch (Exception $e) {
        echo "Address Book Discovery Failed: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Fetch contacts
    try {
        $contacts = $cardDav->fetchContacts();
        print_r($contacts);
    } catch (Exception $e) {
        echo "Fetching Contacts Failed: " . $e->getMessage() . "\n";
    }

    // Create a new contact
    try {
        $newContact = "BEGIN:VCARD
VERSION:3.0
FN:John Doe
N:Doe;John;;;
EMAIL:john.doe@example.com
TEL:+1-555-123-4567
END:VCARD";

        $cardDav->createContact($newContact);
        echo "Contact created successfully!\n";
    } catch (Exception $e) {
        echo "Creating Contact Failed: " . $e->getMessage() . "\n";
    }

} catch (Exception $e) {
    echo "Unexpected Error: " . $e->getMessage();
}