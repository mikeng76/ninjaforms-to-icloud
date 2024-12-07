<?php

class ICloudCardDAV
{
    private $baseUrl = 'https://dav.icloud.com';
    private $username;
    private $password;
    private $addressBookUrl;

    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Discover the CardDAV address book URL
     */
    public function discoverAddressBook()
    {
        $ch = curl_init($this->baseUrl . '/principals/');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Depth: 1',
            'Content-Type: application/xml; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 207) {
            // Parse XML response to find address book URL
            $xml = simplexml_load_string($response);
            $xml->registerXPathNamespace('d', 'DAV:');
            $xml->registerXPathNamespace('card', 'urn:ietf:params:xml:ns:carddav');

            $addressBookPath = $xml->xpath('//d:response[contains(d:propstat/d:prop/d:resourcetype, "addressbook")]/d:href');

            if (!empty($addressBookPath)) {
                $this->addressBookUrl = (string)$addressBookPath[0];
                return $this->addressBookUrl;
            }
        }

        throw new Exception('Could not discover address book');
    }

    /**
     * Fetch all contacts
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 207) {
            return $this->parseContactResponse($response);
        }

        throw new Exception('Failed to fetch contacts');
    }

    /**
     * Create a new contact
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 201) {
            return true;
        }

        throw new Exception('Failed to create contact');
    }

    /**
     * Parse contact response from CardDAV server
     */
    private function parseContactResponse($xmlResponse)
    {
        $xml = simplexml_load_string($xmlResponse);
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

// Example usage
try {
    // Use your iCloud email and an app-specific password
    $cardDav = new ICloudCardDAV('smartriver@icloud.com', 'gioy-zpeq-cals-kwuf');

    // Discover address book
    $addressBookUrl = $cardDav->discoverAddressBook();
    echo "Address Book URL: " . $addressBookUrl . "\n";

    // Fetch contacts
    $contacts = $cardDav->fetchContacts();
    print_r($contacts);

    // Create a new contact (vCard format)
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
    echo "Error: " . $e->getMessage();
}

