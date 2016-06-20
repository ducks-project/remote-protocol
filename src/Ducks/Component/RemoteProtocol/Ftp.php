<?php

namespace Ducks\Component\RemoteProtocol {

    // TODO callback
    class Ftp {

        private $host;

        private $port;

        private $login;

        private $password;

        private $connection;

        private $methods;

        private $callbacks;

        /**
         *
         */
        protected function init( array $params ) {
            foreach ($params as $key => $value) {
                switch ($key) {
                    case 'login':
                        $this->setLogin($value);
                        break;

                    case 'password':
                        $this->setPassword($value);
                        break;

                    case 'timeout':
                        $this->setTimeout($value);
                        break;

                    default:
                        throw new \RuntimeException($key.' is not a valid param');
                        break;
                }
            }
            return $this;
        }

        /**
         * @todo create class instead of indexed keys array
         */
        protected function parseRawList($dir) {
            $items = array();
            if (!($list = ftp_nlist($this->connection, $dir))) {
                return false;
            }
            if (!($rawList = ftp_rawlist($this->connection, $dir))) {
                return false;
            }
            if (count($list) == count($rawList)) {
                foreach ($rawList as $index => $child) {
                    $item = array();
                    $chunks = preg_split("/\s+/", $child);
                    list($item['rights'], $item['number'], $item['user'], $item['group'], $item['size'], $item['month'], $item['day'], $item['time']) = $chunks;
                    $item['type'] = $chunks[0]{0} === 'd' ? 'directory' : 'file';
                    $item['name'] = $list[$index];
                    $items[] = $item;
                }
            }
            return $items;

            foreach ($rawList as $child) {
                $chunks = preg_split("/\s+/", $child);
                list($item['rights'], $item['number'], $item['user'], $item['group'], $item['size'], $item['month'], $item['day'], $item['time']) = $chunks;
                $item['type'] = $chunks[0]{0} === 'd' ? 'directory' : 'file';
                array_splice($chunks, 0, 8);
                $items[implode(" ", $chunks)] = $item;
            }
            return $items;
        }

        /**
         *
         */
        protected function ftp_connect() {
            if (!$this->connection = ftp_connect($this->host, $this->port, $this->timeout)) {
                throw new \RuntimeException('Could not connect to FtpProtocol remote: '.$this->host.':'.$this->port);
            }
            ftp_pasv($this->connection, true);
            return $this;
        }

        protected function isFtpDir($dir) {
            // TODO
            // use parseRawlist?
        }

        /**
         * @param array $params keys should be : login/password/timeout
         */
        public function __construct( $host, $port, array $params=array(), array $methods=null, array $callbacks=null ) {
            $this->timeout = 90;
            $this->setHost($host);
            $this->setPort($port);
            $this->init($params);
            $this->methods = $methods;
            $this->callbacks = $callbacks;
            $this->ftp_connect();
        }

        public function connect() {
            if (empty($this->connection)) {
                $this->ftp_connect();
            }
            if (!ftp_login($this->connection, $this->login, $this->password)) {
                throw new \RuntimeException('Could not authenticated FtpProtocol connection with login and password');
            }
            return $this;
        }

        /**
         *
         */
        public function disconnect() {
            ftp_close($this->connection);
            unset($this->connection);
            return $this;
        }

        /**
         * @param string $pattern Pattern filter
         */
        public function scanDirectory($dir, $sorted=true, $pattern='') {
            if (!is_string($dir)) {
                throw new \UnexpectedValueException($dir.' must be an instance of string, '.gettype($dir).' given.');
            }
            $files = array();
            if ($items = $this->parseRawList($dir)) {
                foreach ($items as $key => $item) {
                    if ($item['type'] == 'file') {
                        if (!empty($pattern) && !preg_match($pattern, $item['name'])) {
                            continue;
                        }
                        $file = $item['name'];
                    }
                }
            } else {
                throw new \RuntimeException('Could not open distant directory: '.$dir);
            }
            if ($sorted) {
                $files = natcasesort($files);
            }
            return $files;
        }

        protected function useDirectory($dir, $local, $pattern='', $isMove=false) {
            $files = $this->scanDirectory($dir, $local, $pattern);
            foreach ($files as $key => $file) {
                if ($isMove) {
                    $files[] = $this->moveFile($dir.$file, $local, $pattern);
                } else {
                    $files[] = $this->downloadFile($dir.$file, $local, $pattern);
                }
            }
            return $files;
        }

        public function downloadDirectory( $dir, $local, $pattern='' ) {
            return $this->useDirectory($dir, $local, $pattern, false);
        }

        /**
         *
         */
        public function moveDirectory($dir, $local, $pattern='') {
            return $this->useDirectory($dir, $local, $pattern, true);
        }

        /**
         *
         * @return SplFileInfo the file downloaded
         */
        public function downloadFile( $file, $local ) {
            if (!is_string($file)) {
                throw new \UnexpectedValueException($file.' must be an instance of string, '.gettype($file).' given.');
            }

            $info = pathinfo($file);
            $filename = $info['basename'];
            if (!($result = ftp_get($this->connection, $local.$filename, $file, FTP_BINARY))) {
                throw new \RuntimeException('An error occured while download distant file: '.$file.' to '.$local);
            }
            return new \SplFileInfo("$local$filename");
        }

        /**
         * Move a file (cut) instead of copy it
         * @return SplFileInfo the file downloaded
         */
        public function moveFile( $file, $local ) {
            $result = $this->downloadFile($file, $local);
            ftp_delete($this->connection, $file);
            return $result;
        }

        public function uploadFile( $local, $remote ) {
            return ftp_put($this->connection, $remote, $local, FTP_BINARY);
        }

        public function createFile( $data, $remote ) {
            if (!is_string($data)) {
                throw new \UnexpectedValueException('$data must be a string, '.gettype($data). 'given.');
            }
            if (!is_string($remote)) {
                throw new \UnexpectedValueException('$remote must be a string, '.gettype($remote). 'given.');
            }

            $result = false;
            $filename = tempnam(sys_get_temp_dir(), 'Ftp');
            if (file_put_contents($filename, $data)) {
                $result = $this->uploadFile($filename, $remote);
                unlink($filename);
            } else {
                throw new \RuntimeException('Could not write temporary file');
            }

            return $result;
        }

        public function setHost( $host ) {
            if (!is_string($host)) {
                throw new \UnexpectedValueException('$host must be a string, '.gettype($host). 'given.');
            }
            $this->host = $host;
            return $this;
        }

        public function getHost() {
            return $this->host;
        }

        public function setPort( $port ) {
            if (!is_numeric($port)) {
                throw new \UnexpectedValueException('$port must be a numeric, '.gettype($port). 'given.');
            }
            $this->port = (int)$port;
            return $this;
        }

        public function getPort() {
            return $this->port;
        }

        public function setLogin( $login ) {
            if (!is_string($login)) {
                throw new \UnexpectedValueException('$login must be a string, '.gettype($login). 'given.');
            }
            $this->login = $login;
            return $this;
        }

        public function getLogin() {
            return $this->login;
        }

        // TODO encode
        public function setPassword( $password ) {
            if (!is_string($password)) {
                throw new \UnexpectedValueException('$password must be a string, '.gettype($password). 'given.');
            }
            $this->password = $password;
            return $this;
        }

        public function getPassword() {
            return $this->password;
        }

        public function setTimeout( $timeout ) {
            if (!is_numeric($timeout)) {
                throw new \UnexpectedValueException('$timeout must be numeric'.gettype($timeout).' given');
            }
            $this->timeout = (int)$timeout;
            return $this;
        }

        public function getTimeout() {
            return $this->timeout;
        }

    }

}
