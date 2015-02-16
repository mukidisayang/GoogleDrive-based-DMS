<?php
/**
 * This file is part of the SimpleDMS package.
 *
 * (c) 2015 Les Polypodes
 * Made in Nantes, France - http://lespolypodes.com
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 *
 * File created by ronan@lespolypodes.com
 */
namespace LesPolypodes\SimpleDMSBundle\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Class GoogleDriveService
 * @package LesPolypodes\SimpleDMSBundle\Service
 */
class GoogleDriveService
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var \Google_Client
     */
    private $client;

    /**
     * @var \Google_Service_Drive
     */
    private $service;

    /**
     * @param ContainerInterface  $container
     * @param TranslatorInterface $translator
     * @param LoggerInterface     $logger
     *
     * @throws InvalidConfigurationException
     */
    public function __construct(ContainerInterface $container, TranslatorInterface $translator, LoggerInterface $logger)
    {
        $this->container = $container;
        // Check if we have the API key
        $rootDir    = $this->container->getParameter('kernel.root_dir');
        $configDir  = $rootDir.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR;
        $apiKeyFile = $configDir.$this->container->getParameter('dms.service_account_key_file');
        if (!file_exists($apiKeyFile)) {
            throw new InvalidConfigurationException('Store your Google API key in '.$apiKeyFile.' - see https://code.google.com/apis/console');
        }
        // Perform API authentication
        $apiKeyFileContents  = file_get_contents($apiKeyFile);
        $serviceAccountEmail = $this->container->getParameter('dms.service_account_email');
        $auth    = new \Google_Auth_AssertionCredentials(
            $serviceAccountEmail,
            array('https://www.googleapis.com/auth/drive'),
            $apiKeyFileContents
        );
        $this->client = new \Google_Client();
        if (isset($_SESSION['service_token'])) {
            $this->client->setAccessToken($_SESSION['service_token']);
        }
        $this->client->setAssertionCredentials($auth);
        /*
        if ($this->client->getAuth()->isAccessTokenExpired()) {
            $this->client->getAuth()->refreshTokenWithAssertion($auth);
        }
        */
        $this->translator = $translator;
        $this->logger = $logger;
        $this->service = new \Google_Service_Drive($this->client);
    }

    /**
     * @return \Google_Service_Drive
     */
    public function get()
    {
        return $this->service;
    }

    /**
     * get Drive File metadata & content
     *
     * @param string|\Google_Service_Drive_DriveFile $resource downloadUrl or Drive File instance.
     *
     * @return array(\Google_Service_Drive_DriveFile resource, HTTP Response Body content)
     */
    public function getFileMetadataAndContent($resource)
    {
        if (!($resource instanceof \Google_Service_Drive_DriveFile)) {
            $resource = $this->getFile($resource);
        }
        $errorMessage = $this->translator->trans('Given File ID do not match any be Google File you can access');
        if (!empty($resource)) {
            $file = $resource['file'];
            $request     = new \Google_Http_Request($file['downloadUrl'], 'GET', null, null);
            $httpRequest = $this->client->getAuth()->authenticatedRequest($request);
            if ($httpRequest->getResponseHttpCode() == 200) {
                return array(
                    'file'  => $resource,
                    'content'   => $httpRequest->getResponseBody(),
                );
            }
        }
        throw new HttpException(500, $errorMessage);
    }

    /**
     * @param string $fileId
     *
     * @return \Google_Service_Drive_DriveFile $file metadata
     */
    public function getFile($fileId)
    {
        try {
            $file = $this->service->files->get($fileId);
            $result = array(
                'modelData'         => $file['modelData'],
                'file'              => $file,
            );

            return $result;
        } catch (\Exception $e) {
            $errorMessage = $this->translator->trans('Given File ID do not match any be Google File you can access');
            throw new HttpException(500, $errorMessage, $e);
        }
    }

    public function getFolders($folderId = null)
    {
        $folderId = !empty($folderId) ? $folderId :  $this->getRootFolderId();
        $result = $this->getChildren($folderId, true);
        $treeView = array();
        foreach ($result as $index => $folder) {
            $treeView[] = array('id' => $folder['id'], 'title' => $folder['title']);
        }

        return $treeView;
    }

    public function getRootFolderId()
    {
        return $this->container->getParameter('dms.root_folder_id');
    }

    /**
     * @param int  $folderId
     * @param bool $isFolder
     *
     * @slink http://stackoverflow.com/a/16299157/490589 (C# version)
     * @link http://stackoverflow.com/a/17743049/490589 (Java version)
     *
     * @return array
     * @throws \Exception
     */
    public function getChildren($folderId, $isFolder = false)
    {
        if (is_null($folderId)) {
            return;
        }

        $nextToken = null;
        $result = array();
        $optParams = new GoogleDriveListParameters();
        $optParams->setMaxResults(1000);
        $optParams->setQuery(GoogleDriveListParameters::NO_TRASH);
        $condition = GoogleDriveListParameters::NO_FOLDERS;
        if ($isFolder) {
            $condition = GoogleDriveListParameters::FOLDERS;
        }
        $optParams->setQuery(sprintf(
            "%s and %s",
            $optParams->getQuery(),
            $condition
        ));
        $optParams->setQuery(sprintf(
            "%s and '%s' in parents",
            $optParams->getQuery(),
            $folderId
        ));

        $this->container->get('monolog.logger.queries')->info($optParams->getJson());

        return array_merge($result, $this->service->files->listFiles($optParams->getArray())->getItems());
    }

    /**
     * @param string                    $relativeInterval
     * @param GoogleDriveListParameters $optParams
     *
     * @link http://php.net/manual/en/datetime.formats.relative.php
     *
     * @return array
     */
    public function getLastModifiedFiles($relativeInterval = '-1 week', $optParams = null)
    {
        $now = new \DateTime($relativeInterval);
        $condition = sprintf("modifiedDate > '%s'", $now->format(\DateTime::ISO8601));
        $optParams = empty($optParams) ? new GoogleDriveListParameters() : $optParams;
        $optParams->setQuery(sprintf("%s and (%s)", $condition, $optParams->getQuery()));

        return $this->getFilesList(false, $optParams);
    }

    /**
     * @param bool                      $isFolder
     * @param GoogleDriveListParameters $optParams
     * @param string                    $parentFolderId
     *
     * @return array
     */
    public function getFilesList($isFolder = false, GoogleDriveListParameters $optParams = null, $parentFolderId = null)
    {
        $files = $this->getFiles($isFolder, $optParams, $parentFolderId);
        $result = array(
            'optParams'         => (!is_null($optParams)) ? $optParams->getArray(true) : null,
            'query'             => $files['query'],
            'has_pagination'    => !empty($files['result']['nextPageToken']),
            'usages'            => $this->getUsage(),
            'count'             => count($files['result']['modelData']['items']),
            'nextPageToken'     => $files['result']['nextPageToken'],
            'list'       => $files['result']['modelData']['items'],
        );

        //usort($result['orderedList'], array($this, "fileCompare"));
        return $result;
    }

    /**
     * @param bool                      $isFolder       = true
     * @param GoogleDriveListParameters $optParams
     * @param string                    $parentFolderId
     *
     * @link https://developers.google.com/drive/web/search-parameters
     *
     * @throws HttpException
     *
     * @return \Google_Service_Drive_FileList
     */
    public function getFiles($isFolder = true, GoogleDriveListParameters $optParams = null, $parentFolderId = "")
    {
        $result = array();
        $optParams = empty($optParams) ? new GoogleDriveListParameters() : $optParams;
        $optParams->setQuery(sprintf("%s and (%s)", GoogleDriveListParameters::NO_TRASH, $optParams->getQuery()));

        // Filters
        $filters = array();
        $filters['type'] = ($isFolder) ? GoogleDriveListParameters::FOLDERS : GoogleDriveListParameters::NO_FOLDERS;
        if (!empty($parentFolderId)) {
            $filters['parentFolder'] = sprintf('"%s" in parents', $parentFolderId);
        }

        foreach ($filters as $filter) {
            $optParams->setQuery(sprintf("%s and (%s)", $optParams->getQuery(), $filter));
        }

        $this->container->get('monolog.logger.queries')->info($optParams->getJson());
        try {
            $files = $this->service->files->listFiles($optParams->getArray());
            $result['query']            = $optParams->getQuery();
            $result['nextPageToken']    = $files->getNextPageToken();
            $result['result']           = $files;
        } catch (\Exception $ge) {
            $errorMessage = sprintf(
                "%s.\n%s.",
                $this->translator->trans('Google Drive cannot authenticate our [email / .p12 key file]'),
                $this->translator->trans('Please check the parameters.yml file')
            );
            $this->logger->error($errorMessage);

            throw new HttpException(500, $errorMessage, $ge);
        }

        return $result;
    }

    /**
     * @return array
     */
    public function getUsage()
    {
        $about       = $this->service->about->get();

        return array(
            "Current user name: "   => $about->getName(),
            "Root folder ID: "      => $about->getRootFolderId(),
            "Total quota (bytes): " => $about->getQuotaBytesTotal(),
            "Used quota (bytes): "  => $about->getQuotaBytesUsed(),
        );
    }

    // TODO
    public function getFilesPerType($relativeInterval = '-1 week', $optParams = null)
    {
        return array();
    }

    // TODO
    public function getFilesTypes($relativeInterval = '-1 week', $optParams = null)
    {
        return array();
    }

    /**
     * @param string                    $nextPageToken
     * @param GoogleDriveListParameters $optParams
     * @param string                    $prefixUrl     route Url
     *
     * @return array
     */
    public function buildPagination($nextPageToken, $optParams = null, $prefixUrl = null)
    {
        $nextUrl = (!empty($prefixUrl)) ? $prefixUrl : null;
        $searchTerm = (!is_null($optParams)) ? $optParams->getSearchTerm() : '';
        if (!empty($nextPageToken)) {
            $nextUrl .= sprintf(
                "?q=%s&pageToken=%s",
                $searchTerm,
                $nextPageToken
            );
        }

        return array(
            'nextUrl' => $nextUrl,
        );
    }

    protected function fileCompare($a, $b)
    {
        return strcmp($a['title'], $b['title']);
    }
}
