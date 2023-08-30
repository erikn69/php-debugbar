<?php

namespace DebugBar\Bridge\SymfonyMailer;

use DebugBar\DataCollector\AssetProvider;
use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;

/**
 * Collects data about sent mail events
 *
 * https://github.com/symfony/mailer
 */
class SymfonyMailCollector extends DataCollector implements Renderable, AssetProvider
{
    private $messages = array();
    private $showDetailed = false;

    public function addSymfonyMessage(SentMessage $message)
    {
        $this->messages[] = $message;
    }

    public function showMessageData()
    {
        $this->showDetailed = true;
    }

    public function collect()
    {
        $mails = array();

        foreach ($this->messages as $sentMessage) {
            $message = $sentMessage->getOriginalMessage();

            $mailData = [
                'subject' => $message->getSubject(),
                'date' => $message->getDate(),
                'return_path' => $message->getReturnPath(),
                'sender' => $message->getSender(),
                'from' => $message->getFrom(),
                'reply_to' => $message->getReplyTo(),
                'to' => $message->getTo(),
                'cc' => $message->getCc(),
                'bcc' => $message->getBcc(),
                'text_body' => $message->getTextBody(),
                'attachments' => $message->getAttachments(),
                'headers' => $message->getHeaders()->toString(),
            ];

            $addressKeys = ['from', 'reply_to', 'to', 'cc', 'bcc'];

            foreach ($addressKeys as $addressKey) {
                $mailData[$addressKey] = array_map(function (Address $address) {
                    return $address->toString();
                }, $mailData[$addressKey]);
            }

            if ($this->showDetailed) {
                $mails[] = array_filter($mailData);
            } else {
                $mails[] = [
                    'to' => $mailData['to'],
                    'subject' => $mailData['subject'],
                    'headers' => $mailData['headers'],
                ];
            }
        }

        return array(
            'count' => count($mails),
            'mails' => $mails,
        );
    }

    public function getName()
    {
        return 'symfonymailer_mails';
    }

    public function getWidgets()
    {
        return array(
            'emails' => array(
                'icon' => 'inbox',
                'widget' => 'PhpDebugBar.Widgets.MailsWidget',
                'map' => 'symfonymailer_mails.mails',
                'default' => '[]',
                'title' => 'Mails'
            ),
            'emails:badge' => array(
                'map' => 'symfonymailer_mails.count',
                'default' => 'null'
            )
        );
    }

    public function getAssets()
    {
        return array(
            'css' => 'widgets/mails/widget.css',
            'js' => 'widgets/mails/widget.js'
        );
    }
}
