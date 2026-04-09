<?php
class PluginRgsupervisionRgapiclient {

    private $baseUrl;
    private $token;
    private $timeout;

    public function __construct($token, $baseUrl = 'https://api.rg-supervision.com', $timeout = 30) {
        $this->token   = $token;
        $this->baseUrl = rtrim($baseUrl, '/') . '/api';
        $this->timeout = (int)$timeout;
    }

    private function get($endpoint, $params = []) {
        $url = $this->baseUrl . $endpoint;
        if ($params) $url .= '?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $this->token, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) return null;
        return json_decode($body, true);
    }

    public function getPedigree($nodeId) {
        $data = $this->get('/node/' . $nodeId . '/pedigree');
        return (is_array($data) && isset($data['data']['nodes'])) ? $data['data']['nodes'] : [];
    }

    public function getTickets($nodeId, $filters = []) {
        $params = ['state' => $filters['state'] ?? 'all'];
        $crits = [];
        foreach (($filters['criticality'] ?? []) as $k => $v) { if ($v) $crits[] = $k; }
        if ($crits) $params['criticality'] = implode(',', $crits);
        if (!empty($filters['agent_types'])) {
            $params['agentTypes'] = is_array($filters['agent_types'])
                ? implode(',', $filters['agent_types']) : $filters['agent_types'];
        }
        $data = $this->get('/ticket/list/' . $nodeId, $params);
        if (!is_array($data)) return [];
        $tickets = $data['data']['tickets'] ?? [];
        if (!is_array($tickets) || empty($tickets)) return [];
        $first = reset($tickets);
        return is_array($first) ? array_values($tickets) : [];
    }

    public function collectAll($nodeId, $filters = [], $nodeName = '') {
        $all = [];
        foreach ($this->getTickets($nodeId, $filters) as $t) {
            if (is_array($t)) {
                $t['node_name_sync'] = $this->nodeName($t, $nodeName);
                $all[] = $t;
            }
        }
        foreach ($this->getPedigree($nodeId) as $child) {
            if (!is_array($child)) continue;
            $cid   = $child['id']   ?? $child['nodeId']   ?? null;
            $cname = $child['name'] ?? $child['nodeName'] ?? (string)$cid;
            if ($cid) $all = array_merge($all, $this->collectAll((string)$cid, $filters, $cname));
        }
        return $all;
    }

    private function nodeName($t, $fallback) {
        foreach (['nodeName','node_name','nodeLabel','node','parentName'] as $f) {
            if (!empty($t[$f]) && is_string($t[$f])) return trim($t[$f]);
        }
        return $fallback;
    }
}
