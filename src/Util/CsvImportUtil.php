<?php

/*
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2015 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\CoreBundle\Util;

use Contao\BackendTemplate;
use Contao\Template;
use Contao\CoreBundle\Exception\CsvImportErrorException;
use Contao\File;
use Contao\FileUpload;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provide methods to handle CSV import.
 *
 * @author Kamil Kuzminski <https://github.com/qzminski>
 */
class CsvImportUtil
{
    const SEPARATOR_COMMA = ',';
    const SEPARATOR_LINEBREAK = "\n";
    const SEPARATOR_SEMICOLON = ';';
    const SEPARATOR_TABULATOR = "\t";

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var Request
     */
    private $request;

    /**
     * Data callback
     * @var callable
     */
    private $callback;

    /**
     * Available separators
     * @var array
     */
    private $separators;

    /**
     * Template
     * @var Template
     */
    private $template;

    /**
     * File uploader
     * @var FileUpload
     */
    private $uploader;

    /**
     * Valid file extensions
     * @var array
     */
    private $fileExtensions = ['csv'];

    /**
     * Upload folder
     * @var string
     */
    private $uploadFolder = 'system/tmp';

    /**
     * Constructor.
     *
     * @param Connection $connection
     * @param Request    $request
     * @param FileUpload $uploader
     */
    public function __construct(
        Connection $connection,
        Request $request,
        FileUpload $uploader
    ) {
        $this->connection = $connection;
        $this->request    = $request;
        $this->uploader   = $uploader;

        $this->setDefaultSeparators();
    }

    /**
     * Generate the CSV import
     *
     * @param int    $maxFileSize
     * @param string $submitLabel
     * @param string $backUrl
     *
     * @return string
     */
    public function generate($maxFileSize, $submitLabel, $backUrl)
    {
        $template              = $this->getTemplate();
        $template->formId      = $this->getFormId();
        $template->backUrl     = $backUrl;
        $template->action      = $this->request->getRequestUri();
        $template->fileMaxSize = $maxFileSize;
        $template->messages    = $this->request->getSession()->getFlashBag()->all();
        $template->uploader    = $this->getUploader()->generateMarkup();
        $template->separators  = $this->getSeparators();
        $template->submitLabel = $submitLabel;

        return $template->parse();
    }

    /**
     * Run the import
     *
     * @param string $field
     *
     * @throws CsvImportErrorException
     */
    public function run($field)
    {
        if (!$this->isFormSubmitted()) {
            throw new CsvImportErrorException('The CSV import form was not submitted');
        }

        $this->storeInDatabase($field, $this->getData());
    }

    /**
     * Return true if the form was submitted
     *
     * @return bool
     */
    public function isFormSubmitted()
    {
        return $this->request->request->get('FORM_SUBMIT') === $this->getFormId();
    }

    /**
     * Get the uploaded files
     *
     * @return array
     */
    public function getUploadedFiles()
    {
        $files = [];

        foreach ($this->getUploader()->uploadTo($this->getUploadFolder()) as $filePath) {
            $file = new File($filePath);

            // Add an error if the file extension is not valid
            if (!in_array($file->extension, $this->getFileExtensions(), true)) {
                $this->request->getSession()->getFlashBag()->add(
                    'error',
                    sprintf($GLOBALS['TL_LANG']['ERR']['filetype'], $file->extension)
                );

                continue;
            }

            $files[] = $filePath;
        }

        return $files;
    }

    /**
     * Get the data from uploaded files
     *
     * @throws CsvImportErrorException
     */
    public function getData()
    {
        $files = $this->getUploadedFiles();

        // Add an error and reload the page if there was no file selected
        if (count($files) === 0) {
            throw new CsvImportErrorException($GLOBALS['TL_LANG']['ERR']['all_fields']);
        }

        return $this->getDataFromFiles($files);
    }

    /**
     * Store data in the database
     *
     * @param string $field
     * @param array  $data
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    public function storeInDatabase($field, array $data)
    {
        $id    = $this->request->query->get('id');
        $table = $this->request->query->get('table');

        $versions = new Versions($table, $id);
        $versions->create();

        $this->connection->prepare("UPDATE $table SET $field=? WHERE id=?")
            ->execute([serialize($data), $id]);
    }

    /**
     * Get data callback
     *
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * Set data callback
     *
     * @param callable $callback
     */
    public function setCallback(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Get separators
     *
     * @return array
     */
    public function getSeparators()
    {
        return $this->separators;
    }

    /**
     * Set separators
     *
     * @param array $separators
     *
     * @throws \InvalidArgumentException
     */
    public function setSeparators(array $separators)
    {
        foreach ($separators as $separator) {
            if (!is_array($separator) || !$separator['value'] || !$separator['label']) {
                throw new \InvalidArgumentException(
                    'Each separator must be an array that contains "value" and "label" keys!'
                );
            }
        }

        $this->separators = $separators;
    }

    /**
     * Set default separators
     */
    private function setDefaultSeparators()
    {
        $this->setSeparators(
            [
                self::SEPARATOR_COMMA     => [
                    'value' => 'comma',
                    'label' => $GLOBALS['TL_LANG']['MSC']['comma'],
                ],
                self::SEPARATOR_LINEBREAK => [
                    'value' => 'linebreak',
                    'label' => $GLOBALS['TL_LANG']['MSC']['linebreak'],
                ],
                self::SEPARATOR_SEMICOLON => [
                    'value' => 'semicolon',
                    'label' => $GLOBALS['TL_LANG']['MSC']['semicolon'],
                ],
                self::SEPARATOR_TABULATOR => [
                    'value' => 'tabulator',
                    'label' => $GLOBALS['TL_LANG']['MSC']['tabulator'],
                ],
            ]
        );
    }

    /**
     * Get the template
     *
     * @return Template
     */
    public function getTemplate()
    {
        if ($this->template === null) {
            $this->template = new BackendTemplate('be_csv_import');
        }

        return $this->template;
    }

    /**
     * Set the template
     *
     * @param Template $template
     */
    public function setTemplate(Template $template)
    {
        $this->template = $template;
    }

    /**
     * Get the uploader
     *
     * @return FileUpload|null
     */
    public function getUploader()
    {
        return $this->uploader;
    }

    /**
     * Set the uploader
     *
     * @param FileUpload $uploader
     */
    public function setUploader(FileUpload $uploader)
    {
        $this->uploader = $uploader;
    }

    /**
     * Get the valid file extensions
     *
     * @return array
     */
    public function getFileExtensions()
    {
        return $this->fileExtensions;
    }

    /**
     * Set the valid file extensions
     *
     * @param array $fileExtensions
     */
    public function setFileExtensions(array $fileExtensions)
    {
        $this->fileExtensions = $fileExtensions;
    }

    /**
     * Get the upload folder
     *
     * @return string
     */
    public function getUploadFolder()
    {
        return $this->uploadFolder;
    }

    /**
     * Set the upload folder
     *
     * @param string $uploadFolder
     */
    public function setUploadFolder($uploadFolder)
    {
        $this->uploadFolder = $uploadFolder;
    }

    /**
     * Get the form ID
     *
     * @return string
     */
    protected function getFormId()
    {
        return 'tl_csv_import_'.$this->request->query->get('key');
    }

    /**
     * Get the data from uploaded files
     *
     * @param array $files
     *
     * @return array
     *
     * @throws CsvImportErrorException
     */
    private function getDataFromFiles(array $files)
    {
        $data = [];

        foreach ($files as $filePath) {
            $file = new File($filePath);

            while (($row = @fgetcsv($file->handle, null, $this->getSeparator())) !== false) {
                $data = call_user_func($this->callback, $data, $row);
            }
        }

        return $data;
    }

    /**
     * Get the separator from the request
     *
     * @return string
     *
     * @throws CsvImportErrorException
     */
    private function getSeparator()
    {
        $mappedSeparator = null;
        $separator       = $this->request->request->get('separator');

        foreach ($this->getSeparators() as $k => $v) {
            if ($v['value'] === $separator) {
                $mappedSeparator = $k;
                break;
            }
        }

        if ($mappedSeparator === null) {
            throw new CsvImportErrorException(sprintf('The CSV separator "%s" is invalid', $separator));
        }

        return $mappedSeparator;
    }
}