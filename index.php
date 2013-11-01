<?php

error_reporting(E_ALL | E_STRICT);
define('DS', DIRECTORY_SEPARATOR);
define('ROOT_DIR', dirname(__FILE__).DS);
define('ALBUM_DIR', ROOT_DIR.'albums'.DS);
define('END_LINE', PHP_SAPI === 'cli' ? PHP_EOL : '<br />');
define('START_TIME', microtime(true));
set_time_limit(0);
header('Content-Type: text/html;charset=utf-8');
 
$config = array(
    'access_token' => 'xxxxxxxxxxxxxxxxxxxx',
    'owner_id' => 1111111111,
    'page_size' => 100,
);


$rr = new RenrenAlbumDowner($config);
$user_profile = $rr->getProfile();

//用户的相册数
$album_count = $user_profile['albumCount'];
echo '用户的相册数: ', $album_count, END_LINE;

//分页获取相册列表
$albums = $rr->getAlbumList($album_count);
//获得照片
$album_photos = $rr->getPhotosOfAlbums($albums);

//下载...
$i = 0;
foreach ($album_photos as $album_photo) {
    $photo_urls = array();
    foreach ($album_photo['photos'] as $photo) {
        $photo_urls[] = $photo['images'][0]['url'];
    }
    if ($photo_urls) {
        $album_path = ALBUM_DIR.$album_photo['album']['id'].DS;
        mkdirp($album_path);
        $ret = $rr->multipleFetchWebpage($photo_urls, false);
        foreach ($ret as $url => $item) {
            foreach ($album_photo['photos'] as $photo) {
                if ($url === $photo['images'][0]['url']) {
                    file_put_contents($album_path.$photo['id'].'.'.strrchr(basename($photo['images'][0]['url']), '.'), $item['data']);
                    $i++;
                    echo '第 ', $i, ' 张照片被下载成功.', END_LINE;
                    break;
                }
            }
        }
    }
}

echo '所有完成, 总用时 ', microtime(true) - START_TIME, END_LINE;

/************************************/
function mkdirp($dir) {
    return (is_dir($dir) or mkdir($dir, 0777, true));
}

function detectEncoding($str) {
    return mb_detect_encoding($str, array('UTF-8', 'CP936', 'BIG-5', 'ASCII'));
}

function convert2gbk($str) {
    return convertEncoding($str, 'CP936');
}

function convert2utf8($str) {
    return convertEncoding($str, 'UTF-8');
}

function reduceData($result, $item) {
    return array_merge($result, $item['data']);
}

function convertEncoding($str, $to_encoding) {
    if ($to_encoding !== ($from_encoding = detectEncoding($str))) {
        return mb_convert_encoding($str, $to_encoding, $from_encoding);
    }
    
    return $str;
}

class RenrenAlbumDowner {

    private $params = array();
    
    public function __construct($params)
    {
        $this->checkEnv();
        $this->setParams($params);
    }
    
    private function setParams(array $params)
    {
        $params = array_map('trim', array_merge(array(
            'access_token' => '',
            'owner_id' => 0,
            'page_size' => 100,
        ), $params));
        
        foreach ($params as $key => $value) {
            empty($value) and $this->showError('没有设置'.$key.'或者为空');
        }
        
        $this->params = $params;
    }
    
    public function getAlbumList($album_count = 1)
    {
        $total_pages = ceil($album_count / $this->params['page_size']);        
        $urls = array();
        
        for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
            $urls[] = sprintf('https://api.renren.com/v2/album/list?access_token=%s&ownerId=%d&pageSize=%d&pageNumber=%d', $this->params['access_token'], $this->params['owner_id'], $this->params['page_size'], $page_number);
        }
        
        if ($urls) {
            return array_reduce($this->multipleFetchWebpage($urls), 'reduceData', array());
        }
        
        return array();
    }
    
    public function checkEnv()
    {
        $extensions = array(
            'openssl',
            'mbstring',
        );
        
        foreach ($extensions as $extension) {
            extension_loaded($extension) or $this->showError('请在php.ini中把extension=php_'.$extension.'.dll的注释去掉');
        }
    }
    
    public function showError($msg)
    {
        die($msg);
    }
    
    public function getPhotosOfAlbums(array $albums)
    {
        empty($albums) and $this->showError('没有相册.');
        
        $photos = array();
        foreach ($albums as $album) {
            $photos[] = array(
                'album' => $album,
                'photos' => $this->getPhotos($album['id'], $album['photoCount']),
            );
        }
        
        return $photos;
    }

    public function getPhotos($album_id, $photo_count)
    {
        $total_pages = ceil($photo_count / $this->params['page_size']);        
        $urls = array();
        
        for ($page_number = 1; $page_number <= $total_pages; $page_number++) {
            $urls[] = sprintf('https://api.renren.com/v2/photo/list?access_token=%s&ownerId=%d&pageSize=%d&pageNumber=%d&password=%s&albumId=%d', $this->params['access_token'], $this->params['owner_id'], $this->params['page_size'], $page_number, 'false', $album_id);
        }
        
        if ($urls) {
            return array_reduce($this->multipleFetchWebpage($urls), 'reduceData', array());
        }
        
        return array();        
    }
    
    public function getProfile()
    {
        $url = sprintf('https://api.renren.com/v2/profile/get?access_token=%s&userId=%d', $this->params['access_token'], $this->params['owner_id']);
        return $this->getResponse($url);
    }
    
    public function multipleFetchWebpage($urls, $is_json = true)
    {
        $mh = curl_multi_init();
        $ch = array();
        
        foreach ($urls as $i => $url) {
            $ch[$i] = curl_init();
            curl_setopt_array($ch[$i], array(
                CURLOPT_URL => $url,
                CURLOPT_REFERER => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_ENCODING => '',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ));
            curl_multi_add_handle($mh, $ch[$i]);
        }
        
        $running = null;
        
        do {
            $status = curl_multi_exec($mh, $running);
            curl_multi_select($mh);
            if (false !== ($info = curl_multi_info_read($mh, $msgs_in_queue))) {
//                echo '剩余 ', $msgs_in_queue, ' 个', END_LINE;
            }
        } while ($status === CURLM_CALL_MULTI_PERFORM || $running);
        
        $res = array();
        foreach ($urls as $i => $url) {
            $res[$url]['error'] = curl_error($ch[$i]);
            if (!empty($res[$url]['error'])) {
                $res[$url]['data'] = '';
            } else {
                $content = curl_multi_getcontent($ch[$i]);
                $res[$url]['data'] = $is_json ? $this->jsonDecode($content) : $content;
            }
            curl_multi_remove_handle($mh, $ch[$i]);
            curl_close($ch[$i]);
        }
        
        curl_multi_close($mh);
        
        return $res;
    }
    
    private function fetchWebpage($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_REFERER => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        
        $response = curl_exec($ch);
        
        curl_close($ch);
        
        return $response;
    }

    private function getResponse($url)
    {
        return $this->jsonDecode($this->fetchWebpage($url));
    }
    
    private function jsonDecode($json)
    {
        if (empty($json)) {
            return array();
        }
        
        $arr = json_decode($json, true);
        return $arr['response'];
    }
}