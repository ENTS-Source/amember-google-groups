<?php

/**
 * Class Am_Plugin_EntsGoogleGroups
 */
class Am_Plugin_EntsGoogleGroups extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_FREE;
    const PLUGIN_REVISION = "1.0.0";

    private $depsLoaded = false;

    protected function loadDependencies()
    {
        if ($this->depsLoaded) return;

        include_once __DIR__ . "/vendor/autoload.php";
        $this->depsLoaded = true;
    }

    function isConfigured()
    {
        $this->loadDependencies();
        $hasConfig = strlen(trim((string)$this->getConfig('client-json-file'))) > 0;
        return $hasConfig;
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->setTitle("ENTS: Google Groups");

        $fs = $form->addFieldSet()->setLabel(___("Client Settings"));
        $fs->addUpload("client-json-file", array(), array("prefix" => "ents-google-groups"))->setLabel(___("client_secret.json file\nSee README section below for more information"));
        $fs->addText("client-subject")->setLabel("Subject\nAlso known as the 'impersonated user'");

        if ($this->isConfigured()) {
            $fs = $form->addFieldSet()->setLabel(___("Google Groups"));
            $groups = $this->getAvailableGroups();
            if ($groups == null) {
                $fs->addElement("html")->setHtml("<div style='color: red;'>Unable to get group listing - please check your settings and try again</div>");
            } else {
                $options = array();
                foreach ($groups as $group) $options[$group] = $group; // needs to be a key:value array for magic select
                $fs->addMagicSelect("signup-groups")->loadOptions($options)->setLabel(___("User signup groups\nGroups to add new users to upon signing up"));
            }
        }

        $form->addFieldsPrefix("misc.ents-google-groups.");
    }

    function onUserAfterInsert(Am_Event $event)
    {
        $user = $event->getUser();
        foreach ($this->getConfig("signup-groups", array()) as $groupKey) {
            $this->addEmailToGroup($user->getEmail(), $groupKey);
        }
    }

    function onUserAfterUpdate(Am_Event $event)
    {
        $user = $event->getUser();
        foreach ($this->getConfig("signup-groups", array()) as $groupKey) {
            $this->addEmailToGroup($user->getEmail(), $groupKey);
        }
    }

    private function addEmailToGroup($email, $groupKey)
    {
        try {
            $client = $this->getGoogleClient();
            $service = new Google_Service_Directory($client);
            $member = new Google_Service_Directory_Member(array("email" => $email, "role" => "MEMBER"));

            $service->members->insert($groupKey, $member);
        } catch (Google_Service_Exception $e) {
            if (strpos($e->getMessage(), "Member already exists") === false) {
                throw new Exception($e->getMessage());
            }
        }
    }

    private function getAvailableGroups()
    {
        $client = $this->getGoogleClient();
        if (!$client) return null;

        $service = new Google_Service_Directory($client);

        $groups = array();

        try {
            $token = null;
            do {
                $params = array("customer" => "my_customer");
                if ($token) $params["pageToken"] = $token;
                $results = $service->groups->listGroups($params);
                if ($results == null) return null;
                foreach ($results->getGroups() as $group) {
                    $groups[] = $group["email"];
                }
                $token = $results->getNextPageToken();
            } while ($token);
        } catch (Exception $e) {
            return null;
        }

        return $groups;
    }

    private function getGoogleClient()
    {
        if (!$this->isConfigured()) return null;

        $client = new Google_Client();
        $client->setApplicationName("aMember Pro");
        $client->setScopes(array(
            Google_Service_Directory::ADMIN_DIRECTORY_GROUP,
            Google_Service_Directory::ADMIN_DIRECTORY_GROUP_MEMBER
        ));

        $subject = $this->getConfig("client-subject", "");

        $client->setAuthConfig($this->getDi()->plugins_storage->getFile($this->getConfig("client-json-file"))->getLocalPath());
        if (strlen(trim($subject)) > 0) $client->setSubject($subject);
        $client->setAccessType("offline");

        return $client;
    }

    function getReadme()
    {
        return <<<CUT
This plugin automatically updates Google distribution groups (Google Groups) when new users are added. The groups are defined above.

To get a client_secret.json file, follow the instructions available at the following links:
* https://developers.google.com/identity/protocols/OAuth2ServiceAccount#creatinganaccount
* https://developers.google.com/identity/protocols/OAuth2ServiceAccount#delegatingauthority

The scopes you'll need for your account are:
* https://www.googleapis.com/auth/admin.directory.group
* https://www.googleapis.com/auth/admin.directory.group.member

Plugin created by ENTS (Edmonton New Technology Society)
* Source: https://github.com/ENTS-Source/amember-google-groups
* For help and support please contact us: https://ents.ca/contact/
CUT;
    }
}