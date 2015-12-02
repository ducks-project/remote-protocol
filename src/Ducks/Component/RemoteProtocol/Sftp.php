<?php

namespace Ducks\Component\RemoteProtocol {

    // TODO callback
    class Sftp {

        private $host;

        private $port;

        private $login;

        private $password; // or passphrase

        private $pubkeyfile;

        private $privkeyfile;

        private $connection;

        private $resource;

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

                    case 'pubkeyfile':
                        $this->setPubkeyfile($value);
                        break;

                    case 'privkeyfile':
                        $this->setPrivkeyfile($value);
                        break;

                    default:
                        throw new \RuntimeException($key.' is not a valid param');
                        break;
                }
            }
            return $this;
        }

        /**
         *
         */
        protected function ssh2_connect() {
            if (!$this->connection = ssh2_connect($this->host, $this->port, $this->methods, $this->callbacks)) {
                throw new \RuntimeException('Could not connect to SftpProtocol remote: '.$this->host.':'.$this->port);
            }
            return $this;
        }

        /**
         * @param array $params keys should be : login/password/pubkeyfile/privkeyfile
         */
        public function __construct( $host, $port, array $params=array(), array $methods=null, array $callbacks=null ) {
            $this->setHost($host);
            $this->setPort($port);
            $this->init($params);
            $this->methods = $methods;
            $this->callbacks = $callbacks;
            $this->ssh2_connect();
        }

        public function connect() {
            if (empty($this->connection)) {
                unset($this->resource);
                $this->ssh2_connect();
            }
            if (empty($this->resource)) {
                if (!empty($this->pubkeyfile) && !empty($this->privkeyfile)) {
                    if (!ssh2_auth_pubkey_file($this->connection, $this->login, $this->pubkeyfile, $this->privkeyfile, $this->password)) {
                        throw new \RuntimeException('Could not authenticated SftpProtocol connection with public key');
                    }
                }
                elseif (!ssh2_auth_password($this->connection, $this->login, $this->password)) {
                    throw new \RuntimeException('Could not authenticated SftpProtocol connection with login and password');
                }
            }
            if (!($this->resource = ssh2_sftp($this->connection))) {
                throw new \RuntimeException('Could  not proccessiong SftpProtocol connection');
            }
            return $this;
        }

        /**
         *
         */
        public function disconnect() {
            unset($this->connection);
            unset($this->resource);
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
            if (is_dir('ssh2.sftp://'.$this->resource.$dir)) {
                if ($dh = opendir('ssh2.sftp://'.$this->resource.$dir)) {
                    while (($file = readdir($dh)) !== false) {
                        if (filetype($dir.$file) && filetype($dir.$file) == 'file') {
                            $files[] = $file;
                        }
                    }
                    closedir($dh);
                } else {
                    throw new \RuntimeException('Could not open distant directory: '.$dir);
                }
            } else {
                throw new \RuntimeException($dir.' is not a valid directory');
            }
            if ($sorted) {
                $files = natcasesort($files);
            }
            return $files;
        }

        protected function useDirectory($dir, $local, $pattern='', $isMove=false) {
            if (!is_string($dir)) {
                throw new \UnexpectedValueException($dir.' must be an instance of string, '.gettype($dir).' given.');
            }
            $files = array();
            $sshDir = 'ssh2.sftp://'.$this->resource.$dir;
            if (is_dir($sshDir)) {
                if ($dh = opendir($sshDir)) {
                    while (($file = readdir($dh)) !== false) {
                        if (filetype($sshDir.$file) && filetype($sshDir.$file) == 'file') {
                            if (!empty($pattern) && !preg_match($pattern, $file)) {
                                continue;
                            }
                            if ($isMove) {
                                $files[] = $this->moveFile($dir.$file, $local, $pattern);
                            } else {
                                $files[] = $this->downloadFile($dir.$file, $local, $pattern);
                            }
                        }
                    }
                    closedir($dh);
                } else {
                    throw new \RuntimeException('Could not open distant directory: '.$dir);
                }
            } else {
                throw new \RuntimeException($dir.' is not a valid directory');
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
         * @return SplFileInfo the file downloaded
         */
        public function downloadFile( $file, $local ) {
            if (!is_string($file)) {
                throw new \UnexpectedValueException($dir.' must be an instance of string, '.gettype($file).' given.');
            }
            $info = pathinfo($file);
            $filename = $info['basename'];
            if (true/*!ssh2_scp_recv($this->connection, $file, "$local$filename")*/) {
                if ( !($content = file_get_contents('ssh2.sftp://'.$this->resource.$file)) ) {
                    throw new \RuntimeException('Could not open distant file: '.$file);
                } else {
                    if (!file_put_contents("$local$filename", $content)) {
                        throw new \RuntimeException('Could not write content to: '.$local);
                    }
                }
            }

            // old way does not work
            /*$stream = fopen('ssh2.sftp://'.$this->resource.$file, 'r');
            if ($stream === false) {
                throw new \RuntimeException('Could not open distant file: '.$file);
            }
            $data = fread($stream, filesize('ssh2.sftp://'.$this->resource.'/'.$file));
            fclose($stream);
            $stream = fopen("$local$filename", 'w');
            if ($stream === false) {
                throw new \RuntimeException('Could not open local file: '."$local$filename");
            }
            if (fwrite($stream, $data) === false) {
                throw new \RuntimeException('Could not write content to: '.$local);
            }
            fclose($stream);*/
            return new \SplFileInfo("$local$filename");
        }

        /**
         * Move a file (cut) instead of copy it
         * @return SplFileInfo the file downloaded
         */
        public function moveFile( $file, $local ) {
            $result = $this->downloadFile($file, $local);
            ssh2_sftp_unlink($this->resource, $file);
            return $result;
        }

        public function uploadFile( $local, $remote ) {
            if (($data = file_get_contents($local)) === false) {
                throw new \RuntimeException('Could not open local file: '.$local);
            }
            //try {
                $result = $this->createFile($data, $remote);
            //} catch (\RuntimeException $e) {
            //    $result = ssh2_scp_send($this->connection, $local, $remote);
            //}
            return $result;
        }

        public function createFile( $data, $remote ) {
            if (!is_string($data)) {
                throw new \UnexpectedValueException('$data must be a string, '.gettype($data). 'given.');
            }
            if (!is_string($remote)) {
                throw new \UnexpectedValueException('$remote must be a string, '.gettype($remote). 'given.');
            }
            if (empty($this->resource)) {
                throw new \RuntimeException('Resource SftpProtocol is not correctly initialize');
            }
            $stream = fopen('ssh2.sftp://'.$this->resource.$remote, 'w');
            if ($stream === false) {
                throw new \RuntimeException('Could not open distant file: '.$remote);
            }
            if (fwrite($stream, $data) === false) {
                throw new \RuntimeException('Could not write content to: '.$remote);
            }
            fclose($stream);
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

        public function setPubkeyfile( $pubkeyfile ) {
            if (!is_string($pubkeyfile)) {
                throw new \UnexpectedValueException('$host must be a string, '.gettype($pubkeyfile). 'given.');
            }
            $this->pubkeyfile = $pubkeyfile;
            return $this;
        }

        public function getPubkeyfile() {
            return $this->pubkeyfile;
        }

        public function setPrivkeyfile( $privkeyfile ) {
            if (!is_string($privkeyfile)) {
                throw new \UnexpectedValueException('$privkeyfile must be a string, '.gettype($privkeyfile). 'given.');
            }
            $this->privkeyfile = $privkeyfile;
            return $this;
        }

        public function getPrivkeyfile() {
            return $this->privkeyfile;
        }

        public function getResource() {
            return $this->resource;
        }

    }

}
