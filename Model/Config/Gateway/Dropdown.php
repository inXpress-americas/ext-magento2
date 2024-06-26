<?php

namespace InXpress\InXpressRating\Model\Config\Gateway;

class Dropdown implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     *
     * @codeCoverageIgnore
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'US',
                'label' => 'US'
            ],
            [
                'value' => 'CA',
                'label' => 'CA',
            ],
            [
                'value' => 'UK',
                'label' => 'UK'
            ],
            [
                'value' => 'AU',
                'label' => 'AU',
            ]
        ];
    }
}
