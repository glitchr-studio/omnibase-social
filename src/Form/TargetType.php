<?php

namespace Base\Social\Form;

use Base\Social\Entity\SocialPostTarget;
use Base\Social\Entity\Template;
use Base\Social\Entity\SocialPost;
use Omnipost\Platform;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One network of a post in the back office: whether it goes there, and
 * what it says there when not what the post says - left empty, the post's
 * caption, title, hashtags and template stand. The network's extras below.
 *
 * The texts name their domain (@social.…): base-bundle gives every field
 * the "fields" domain, which the form's own translation_domain does not reach.
 */
class TargetType extends AbstractType
{
    public function __construct(#[Autowire('%social.providers%')] private readonly array $providers = ['instagram'])
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $platforms = [];
        foreach ($this->providers as $name) {
            $platforms[Platform::tryFrom($name)?->label() ?? $name] = $name;
        }

        $builder
            ->add('platform', ChoiceType::class, ['label' => '@social.admin.target.platform', 'choices' => $platforms])
            ->add('enabled', CheckboxType::class, ['label' => '@social.admin.target.enabled', 'required' => false])
            ->add('caption', TextareaType::class, ['label' => '@social.admin.target.caption', 'required' => false, 'help' => '@social.admin.target.caption_help', 'attr' => ['rows' => 3]])
            ->add('title', TextType::class, ['label' => '@social.admin.target.title', 'required' => false, 'help' => '@social.admin.target.title_help'])
            ->add('tags', TextType::class, ['label' => '@social.admin.target.tags', 'required' => false, 'help' => '@social.admin.target.tags_help'])
            ->add('template', EntityType::class, ['label' => '@social.admin.target.template', 'class' => Template::class, 'required' => false, 'placeholder' => '@social.admin.target.template_inherit'])
            ->add(
                $builder->create('options', FormType::class, ['label' => false, 'required' => false, 'data_class' => null])
                    ->add('share_to_feed', ChoiceType::class, ['label' => '@social.admin.target.share_to_feed', 'required' => false, 'placeholder' => '@social.admin.target.default', 'choices' => ['@social.admin.target.yes' => true, '@social.admin.target.no' => false]])
                    ->add('privacy', ChoiceType::class, ['label' => '@social.admin.target.privacy', 'required' => false, 'placeholder' => '@social.admin.target.default', 'choices' => ['@social.admin.target.privacy_public' => 'public', '@social.admin.target.privacy_unlisted' => 'unlisted', '@social.admin.target.privacy_private' => 'private']])
            );

        // Hashtags typed as a line: "violin, bach #concert".
        $builder->get('tags')->addModelTransformer(new CallbackTransformer(
            static fn (?array $tags): string => implode(' ', array_map(static fn (string $tag) => '#'.$tag, $tags ?? [])),
            static fn (?string $line): ?array => SocialPost::cleanTags(preg_split('/[\s,;]+/u', (string) $line) ?: []) ?: null,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SocialPostTarget::class,
            'empty_data' => fn () => new SocialPostTarget($this->providers[0] ?? null),
            'translation_domain' => 'social',
        ]);
    }
}
