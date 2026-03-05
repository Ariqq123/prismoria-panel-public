<?php

namespace Pterodactyl\Classes\Cloudflare\Endpoints;

use Pterodactyl\Classes\Cloudflare\Adapter\Adapter;

class DNS implements API
{
    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @param Adapter $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @param string $zoneID
     * @param $options
     * @return bool
     */
    public function addRecord(string $zoneID, $options): bool {
        $user = $this->adapter->post('zones/' . $zoneID . '/dns_records', [], $options);

        $body = json_decode($user->getBody());

        if (isset($body->result->id)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $zoneID
     * @param string $type
     * @param string $name
     * @param string $content
     * @param int $page
     * @param int $perPage
     * @param string $order
     * @param string $direction
     * @param string $match
     * @return \stdClass
     */
    public function listRecords(
        string $zoneID,
        string $type = '',
        string $name = '',
        string $content = '',
        int $page = 1,
        int $perPage = 20,
        string $order = '',
        string $direction = '',
        string $match = 'all'
    ): \stdClass {
        $query = [
            'page' => $page,
            'per_page' => $perPage,
            'match' => $match
        ];

        if (!empty($type)) {
            $query['type'] = $type;
        }

        if (!empty($name)) {
            $query['name'] = $name;
        }

        if (!empty($content)) {
            $query['content'] = $content;
        }

        if (!empty($order)) {
            $query['order'] = $order;
        }

        if (!empty($direction)) {
            $query['direction'] = $direction;
        }

        $user = $this->adapter->get('zones/' . $zoneID . '/dns_records', $query, []);
        $body = json_decode($user->getBody());

        return (object)['result' => $body->result, 'result_info' => $body->result_info];
    }

    /**
     * @param string $zoneID
     * @param string $recordID
     * @return \stdClass
     */
    public function getRecordDetails(string $zoneID, string $recordID): \stdClass
    {
        $user = $this->adapter->get('zones/' . $zoneID . '/dns_records/' . $recordID, [], []);
        $body = json_decode($user->getBody());
        return $body->result;
    }

    /**
     * @param string $zoneID
     * @param string $recordID
     * @param array $details
     * @return \stdClass
     */
    public function updateRecordDetails(string $zoneID, string $recordID, array $details): \stdClass
    {
        $response = $this->adapter->put('zones/' . $zoneID . '/dns_records/' . $recordID, [], $details);
        return json_decode($response->getBody());
    }

    /**
     * @param string $zoneID
     * @param string $recordID
     * @return bool
     */
    public function deleteRecord(string $zoneID, string $recordID): bool
    {
        $user = $this->adapter->delete('zones/' . $zoneID . '/dns_records/' . $recordID, [], []);

        $body = json_decode($user->getBody());

        if (isset($body->result->id)) {
            return true;
        }

        return false;
    }
}
