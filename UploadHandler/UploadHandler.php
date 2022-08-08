<?php

namespace TuxGasy\FileUploadBundle\UploadHandler;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class UploadHandler
{
    private $requestStack;
    private $formFactory;
    private $translator;
    private $router;
    private $uploadDir;
    private $options;

    public function __construct(RequestStack $requestStack, FormFactoryInterface $formFactory, TranslatorInterface $translator, UrlGeneratorInterface $router, $uploadDir)
    {
        $this->requestStack = $requestStack;
        $this->formFactory = $formFactory;
        $this->translator = $translator;
        $this->router = $router;
        $this->uploadDir = $uploadDir;
    }

    private function setOptions($options = null)
    {
        $this->options = [
            'upload_dir' => $this->uploadDir,
            'upload_url' => null,
            'field_name' => 'files',
            'file_max_size' => '10M',
            'file_mime_types' => [
                'image/jpeg',
                'image/png',
                'application/pdf',
            ],
            'file_mime_types_message' => $this->translator->trans('Please upload an image or a PDF document.'),
            'file' => null,
        ];

        if ($options) {
            $this->options = $options + $this->options;
        }

        if (null == $this->options['upload_dir']) {
            throw new \Exception('Upload dir is null.');
        }
    }

    private function getFilepath($isFile = true)
    {
        $filepath = $this->options['upload_dir'].'/'.$this->options['file'];
        if (!file_exists($filepath)) {
            throw new HttpException(404, 'Not Found');
        }

        if ($isFile and !is_file($filepath)) {
            throw new HttpException(404, 'Not Found');
        }

        return $filepath;
    }

    private function getFileUrl(\SplFileInfo $file)
    {
        if ($this->options['upload_url']) {
            if (is_callable($this->options['upload_url'])) { // if function
                return $this->options['upload_url']($file);
            } else { // string as route name
                return $this->router->generate($this->options['upload_url'], ['file' => $file->getFilename()]);
            }
        }

        return '';
    }

    private function post()
    {
        $form = $this->formFactory
            ->createBuilder('Symfony\Component\Form\Extension\Core\Type\FormType', null, ['csrf_protection' => false])
            ->add($this->options['field_name'], FileType::class, [
                'multiple' => true,
                'constraints' => [
                    new Assert\All([ // as allow multi files, apply constraints to all array
                        'constraints' => [
                            new Assert\NotBlank([
                                'message' => $this->translator->trans('No file was uploaded.'),
                            ]),
                            new Assert\File([
                                'maxSize' => $this->options['file_max_size'],
                                'mimeTypes' => $this->options['file_mime_types'],
                                'mimeTypesMessage' => $this->options['file_mime_types_message'],
                            ]),
                        ],
                    ]),
                ],
            ])
            ->getForm()
        ;

        $request = $this->requestStack->getCurrentRequest();
        $form->handleRequest($request);

        if (!$form->isSubmitted() or !$form->isValid()) {
            return new JsonResponse(
                $this->serializeFormErrors($form),
                400
            );
        }

        $fs = new Filesystem();
        if (!$fs->exists($this->options['upload_dir'])) {
            $fs->mkdir($this->options['upload_dir']);
        }

        $response = [];
        $data = $form->getData();
        foreach ($data[$this->options['field_name']] as $uploadedFile) {
            $originalFilename = $this->cleanFilename($uploadedFile->getClientOriginalName());

            if ($this->uploadDir == $this->options['upload_dir']) {
                $tmpfile = tempnam($this->options['upload_dir'], '');
                if (false === $tmpfile or $this->options['upload_dir'] != dirname($tmpfile)) {
                    throw new \Exception('Failed to generate temporary file.');
                }

                $filename = basename($tmpfile);
            } else {
                $filename = $this->getUniqueFilename($this->options['upload_dir'], $uploadedFile->getClientOriginalName());
            }

            $file = $uploadedFile->move($this->options['upload_dir'], $filename);

            $response[] = [
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'url' => $this->getFileUrl($file),
            ];
        }

        return new JsonResponse($response);
    }

    private function get()
    {
        $filepath = $this->getFilepath(null);

        // If directory
        if (is_dir($filepath)) {
            $response = [];

            $finder = new Finder();
            $finder
                ->files()
                ->in($filepath)
                ->depth('== 0')
            ;

            foreach ($finder as $file) {
                $response[] = [
                    'filename' => $file->getFilename(),
                    'url' => $this->getFileUrl($file),
                ];
            }

            return new JsonResponse($response);
        }

        // Else is file
        $response = new BinaryFileResponse($filepath);

        if (preg_match('/\.(gif|jpe?g|png|pdf)$/i', $filepath)) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        } else {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        }

        return $response;
    }

    private function delete()
    {
        $filepath = $this->getFilepath();

        $fs = new Filesystem();
        $fs->remove($filepath);

        return new JsonResponse();
    }

    public function cleanFilename($filename)
    {
        $fileInfos = preg_replace('/[^a-zA-Z0-9-_]/u', '', pathinfo($filename));
        if (null === $fileInfos) {
            throw new \Exception('Fail to clean filename "'.$filename.'"');
        }

        $filename = $fileInfos['filename'] ? $fileInfos['filename'] : uniqid();

        if (isset($fileInfos['extension'])) {
            $filename .= '.'.$fileInfos['extension'];
        }

        return $filename;
    }

    public function counterFilename($filename)
    {
        return preg_replace_callback(
            '/(?:(?:_([1-9]+))?(\.[^.]+))?$/',
            function ($matches) {
                $index = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
                $ext = isset($matches[2]) ? $matches[2] : '';

                return '_'.$index.$ext;
            },
            $filename,
            1
        );
    }

    public function getUniqueFilename($dir, $filename)
    {
        $filename = $this->cleanFilename($filename);

        while (file_exists($dir.'/'.$filename)) {
            $filename = $this->counterFilename($filename);
        }

        return $filename;
    }

    // Inspired by https://symfonycasts.com/screencast/symfony-rest2/validation-errors-response
    public function serializeFormErrors(FormInterface $form)
    {
        $errors = [];

        foreach ($form->getErrors() as $error) {
            if (!$error instanceof FormError) {
                throw new \Exception('Should never happened !');
            }

            $errors[] = $error->getMessage();
        }

        foreach ($form->all() as $childForm) {
            if (!$childForm instanceof FormInterface) {
                throw new \Exception('Should never happened !');
            }

            $childErrors = $this->serializeFormErrors($childForm);
            if (!empty($childErrors)) {
                $errors[$childForm->getName()] = $childErrors;
            }
        }

        return $errors;
    }

    public function initialize($options = null)
    {
        $this->setOptions($options);

        switch ($this->requestStack->getCurrentRequest()->getMethod()) {
            case 'POST':
                return $this->post();
            case 'GET':
                return $this->get();
            case 'DELETE':
                return $this->delete();
            default:
                throw new HttpException(405, 'Method Not Allowed');
        }
    }
}
