<?php

namespace InXpress\InXpressRating\Model\Config\Gateway;

class Dropdown implements \Magento\Framework\Option\ArrayInterface
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
