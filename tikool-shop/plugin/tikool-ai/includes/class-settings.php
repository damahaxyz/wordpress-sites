<?php
/**
 * Stored provider configuration.
 */

declare(strict_types=1);

namespace Tikool\AI;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION_NAME = 'tikool_ai_settings';
    public const OPTION_GROUP = 'tikool_ai';
    public const DEFAULT_ENDPOINT = 'https://ark.cn-beijing.volces.com/api/v3/images/generations';
    public const DEFAULT_MODEL = 'doubao-seedream-5-0-flash-260915';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'endpoint' => self::DEFAULT_ENDPOINT,
            'api_key' => '',
            'model' => self::DEFAULT_MODEL,
            'size' => '2K',
            'response_format' => 'url',
            'sequential_image_generation' => 'disabled',
            'watermark' => true,
            'timeout' => 120,
            'prompt_profiles' => [
                'pet_portrait_preview' => [
                    'label' => __('宠物肖像预览', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('将输入图片转换为适合定制商品预览的宠物肖像。保留宠物的品种、毛色、花纹、五官特征、姿势和主体数量，不增加或删除宠物。{{style_prompt}} {{user_prompt}}', 'tikool-ai'),
                ],
                'pet_portrait_minimalist' => [
                    'label' => __('Minimalist Pet Portrait', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('Create a refined minimalist pet portrait from the input photo for {{product_name}}. Use a strict {{orientation}} {{aspect_ratio}} composition (width:height) matching the {{print_dimensions}} print area, and fill the complete canvas at that ratio. Preserve the pet’s exact identity, species, breed, coat colors, markings, facial proportions, eye color, ear shape, pose, accessories, and number of pets. Keep the pet as the clear main subject. Use a clean white, beige, light gray, or cream background, soft studio light, gentle natural shadows, and crisp but elegant detail. Remove visual clutter. Do not add text, frames, borders, watermarks, people, props, or extra animals.', 'tikool-ai'),
                ],
                'pet_portrait_watercolor' => [
                    'label' => __('Watercolor Pet Portrait', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('Create a refined watercolor pet portrait from the input photo for {{product_name}}. Use a strict {{orientation}} {{aspect_ratio}} composition (width:height) matching the {{print_dimensions}} print area, and fill the complete canvas at that ratio. Preserve every pet’s exact identity, species, breed, coat colors, distinctive markings, facial proportions, eye color, ear shape, pose, accessories, relative size, and the original number of pets. Center the complete subject group with generous negative space and do not crop ears, paws, tails, or accessories. Use clearly visible layered watercolor washes, controlled wet-on-wet blooms, delicate dry-brush fur detail, natural pigment granulation, and continuous cold-pressed watercolor paper texture. Keep the eyes, nose, face, and identifying markings precise. {{background_prompt}} Add only a few restrained pigment splashes close to the subject and a subtle irregular watercolor grounding wash beneath it. Do not add text, frames, borders, rounded corners, watermarks, signatures, people, props, scenery, flowers, furniture, or extra animals. Do not create a collage or grid.', 'tikool-ai'),
                    'variants' => [
                        'warm_ivory' => [
                            'label' => __('Warm Ivory', 'tikool-ai'),
                            'prompt' => __('Use a spacious low-saturation warm ivory watercolor wash background with faint cream and warm-gray tonal variation. Keep the edges close to the natural paper color, and deepen the wash only slightly behind pale fur so the silhouette remains clear.', 'tikool-ai'),
                        ],
                        'mist_blue' => [
                            'label' => __('Mist Blue', 'tikool-ai'),
                            'prompt' => __('Use a spacious low-saturation misty blue-gray watercolor wash background. Make the wash slightly deeper behind the subject, especially behind white or pale fur, then let it fade naturally toward the paper-colored edges.', 'tikool-ai'),
                        ],
                        'soft_sage' => [
                            'label' => __('Soft Sage', 'tikool-ai'),
                            'prompt' => __('Use a spacious low-saturation pale sage-green watercolor wash background with restrained gray undertones. Keep it soft and quiet, with slightly stronger pigment behind pale fur and a natural fade toward the edges.', 'tikool-ai'),
                        ],
                        'dusty_blush' => [
                            'label' => __('Dusty Blush', 'tikool-ai'),
                            'prompt' => __('Use a spacious low-saturation dusty blush-gray watercolor wash background with subtle warm-gray variation. Keep it muted rather than pink or sweet, and fade the color gently toward the natural paper edges.', 'tikool-ai'),
                        ],
                    ],
                ],
                'pet_portrait_oil' => [
                    'label' => __('Classic Oil Painting Pet Portrait', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('Create a classic fine-art oil painting pet portrait from the input photo for {{product_name}}. Use a strict {{orientation}} {{aspect_ratio}} composition (width:height) matching the {{print_dimensions}} print area, and fill the complete canvas at that ratio. Preserve the pet’s exact identity, species, breed, coat colors, distinctive markings, facial proportions, eye color, pose, accessories, and number of pets. Use traditional layered oil brushwork, rich but controlled color, softly modeled museum-style light, realistic fur detail, and a simple timeless painterly background that does not compete with the pet. Do not add text, frames, borders, watermarks, people, props, costumes, or extra animals.', 'tikool-ai'),
                ],
                'blanket_portrait_minimalist_cream' => [
                    'label' => __('Blanket · Warm Minimalist Cream', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('根据提供的照片，先识别并完整保留照片中的全部主体，确认主体数量、类别以及各主体之间的相对位置、大小和互动关系，再创作适合 {{product_name}} 全幅印刷的 Warm Minimalist Portrait（温暖极简肖像）平面图稿。

输出画面必须采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例填满完整画布、延伸至四边出血区域。主体组合位于视觉中心，占画面高度约 60% 至 72%，上方和四周保留充足且均衡的安全印刷空间；不得裁切耳朵、头顶、爪子、尾巴或原图中完整可见的重要配饰，也不要凭空补画原图中不可见的身体部位。

高度忠实还原每一个主体的身份特征，包括类别、品种、毛色或表面颜色、独特斑纹、毛发或材质纹理、头部形态、面部比例、眼距、眼睛大小与颜色、口鼻结构、耳朵形状、身体比例、姿态、朝向、表情以及实际佩戴的服装和配饰。不得为了可爱、美观或构图统一而改变脸型、放大眼睛、缩短口鼻、增加毛量或标准化不同主体。

如果原图包含多个主体，必须保留全部主体以及它们原有的相对大小、位置、距离、前后关系和互动，不得增加、删除、替换、复制、合并、拆分或重新排列主体。

仅移除原照片中的环境、地面杂物、牵引绳和与主体无直接关系的元素。主体采用清晰、自然、细腻的现代数字插画表现，轮廓干净但不生硬，面部和关键识别区域清楚，光影柔和均衡。不要出现水彩晕染、颜料飞溅、油画厚涂、粗黑卡通描边或照片滤镜感。

{{background_prompt}}

为适应短绒法兰绒印刷，适度增强主体轮廓分离度、关键斑纹和面部局部对比，避免依赖过细线条、微弱灰阶或容易在织物上消失的小细节。背景必须完整延伸至画面四边，不得出现透明区域、白边、边框或相框。

这是毛毯的二维印刷图稿，不是商品展示照片。不要生成毛毯、布料纹理、绒毛、褶皱、缝线、床铺、沙发、房间、墙面、人物手持效果或产品模型。不要添加花朵、玩具、家具、无关道具或复杂装饰。不得生成任何文字、姓名、字母、数字、日期、logo、标志、水印或签名；姓名和文字将由网站中的独立文字图层添加。', 'tikool-ai'),
                    'variants' => [
                        'warm_ivory' => [
                            'label' => __('Warm Ivory', 'tikool-ai'),
                            'prompt' => __('背景采用干净、明亮、低饱和的 Warm Ivory（温暖象牙白）哑光基调，以柔和象牙白、浅奶油色和极淡暖灰为主。主体后方允许出现非常轻微的暖色明暗变化，以增强浅色毛发的轮廓分离度，但不得形成明显光圈、聚光、强渐变或色块。背景保留极细微、均匀、几乎不可察觉的自然纸面颗粒，四周连续延伸至出血边缘；主体底部仅加入很浅、边缘柔和的接触阴影，不形成地板或台面。不得加入图案、水彩晕染、飞溅、花卉或装饰元素。', 'tikool-ai'),
                        ],
                        'oatmeal_beige' => [
                            'label' => __('Oatmeal Beige', 'tikool-ai'),
                            'prompt' => __('背景采用柔和、温暖、低饱和的 Oatmeal Beige（燕麦米色）哑光基调，以浅燕麦色、淡米色和极浅灰褐色形成克制的自然层次。主体后方可略微提亮，外围仅作非常轻微的暖灰变化，确保深色与浅色主体都清晰可辨，但不得出现明显渐变、光晕或复古泛黄。保留宽敞干净的负空间和极细腻的纸面颗粒，背景连续延伸至四边；底部只保留浅而柔和的接触阴影，不形成地面。不得加入图案、笔刷痕迹、水彩效果或额外装饰。', 'tikool-ai'),
                        ],
                        'pale_sage' => [
                            'label' => __('Pale Sage', 'tikool-ai'),
                            'prompt' => __('背景采用低饱和、偏灰且明亮的 Pale Sage（浅鼠尾草绿）哑光基调，整体自然、安静、清新而高级。颜色保持浅淡，不得偏鲜绿、黄绿或深橄榄色；主体后方允许有非常轻微的中性提亮，使轮廓清楚，但不形成光圈或明显渐变。背景平整干净，仅保留几乎不可察觉的细腻自然颗粒并延伸至四边；主体底部加入很浅的柔和接触阴影。不得加入植物、叶片、图案、水彩晕染或其他装饰。', 'tikool-ai'),
                        ],
                        'dusty_blue' => [
                            'label' => __('Dusty Blue', 'tikool-ai'),
                            'prompt' => __('背景采用明亮、低饱和的 Dusty Blue（柔和灰蓝色）哑光基调，以雾蓝、浅灰蓝和中性浅灰形成克制层次，整体安静、清爽、现代。避免鲜蓝、深海军蓝、过冷或大面积深色；主体后方可略微变亮或变暖，以保证主体毛色与背景分离，但不得形成明显光晕或聚光。背景保持均匀、宽敞，仅有极细微纸面颗粒并连续延伸至四边；底部只使用很浅的自然接触阴影。不得加入云朵、图案、水彩笔触或装饰元素。', 'tikool-ai'),
                        ],
                    ],
                ],
                'blanket_portrait_watercolor_pastel' => [
                    'label' => __('Blanket · Pastel Watercolor', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('根据提供的照片，先识别并完整保留照片中的全部主体，确认主体数量、类别以及各主体之间的相对位置、大小和互动关系，再创作适合 {{product_name}} 全幅印刷的 Pastel Watercolor Portrait（柔和粉彩水彩肖像）平面图稿。

输出画面必须采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例填满完整画布、延伸至四边出血区域。主体组合位于视觉中心，占画面高度约 60% 至 72%，四周保留自然且充足的安全印刷空间；不得裁切耳朵、头顶、爪子、尾巴或原图中完整可见的重要配饰，也不要凭空补画原图中不可见的身体部位。

高度忠实还原每一个主体的身份特征，包括类别、品种、毛色或表面颜色、独特斑纹、头部形态、面部比例、眼距、眼睛大小与颜色、口鼻结构、耳朵形状、身体比例、姿态、朝向、表情以及实际佩戴的服装和配饰。主体面部和主要识别区域为最高还原优先级，不得为了可爱或艺术效果而改变脸型、五官比例、头身比例、毛量或关键花纹。

如果原图包含多个主体，必须保留全部主体以及它们原有的相对大小、位置、距离、前后关系和互动，不得增加、删除、替换、复制、合并、拆分或重新排列主体。

整体必须呈现真实手绘水彩特征：自然透明的分层叠色、受控的湿画法晕染、少量干笔毛发细节、自然颜料颗粒、冷压水彩纸纹理以及局部柔和溶解的边缘。面部、眼睛、鼻部和关键斑纹保持清晰，外围毛发可适度松散地融入背景。避免数码滤镜感、塑料感、粗黑描边、油画厚涂和过度模糊。

{{background_prompt}}

主体周围只允许少量、分散、低饱和的淡彩墨点或微小飞溅，装饰不得覆盖面部或关键轮廓，也不得形成凌乱的大面积喷溅。为适应短绒法兰绒印刷，适度增强关键轮廓、斑纹和局部对比，避免过细、过淡、容易消失的线条。背景水彩与纸张纹理必须自然连续至画面四边，不得出现透明区域、白边、边框或相框。

仅移除原照片中的环境、地面杂物、牵引绳和无关元素。这是毛毯的二维印刷图稿，不是商品展示照片。不要生成毛毯、布料纹理、绒毛、褶皱、缝线、床铺、沙发、房间、人物手持效果或产品模型。不得生成任何文字、姓名、字母、数字、日期、logo、标志、水印或签名；姓名和文字将由网站中的独立文字图层添加。', 'tikool-ai'),
                    'variants' => [
                        'warm_ivory' => [
                            'label' => __('Warm Ivory', 'tikool-ai'),
                            'prompt' => __('背景采用非常淡的 Warm Ivory（温暖象牙白）冷压水彩纸效果，整体温暖、明亮、低饱和、通透。不要做成均匀纯色；在主体后方加入一层轻薄、自然、不规则的象牙白、浅奶油色与极淡暖灰水彩 wash，中心略有色调层次，向四周逐渐变淡并融入纸张底色，最终自然延伸至出血边缘。主体底部加入一圈很淡、松散的暖米白水痕式接触阴影，仅用于自然落地，不形成地面、深色带或明显色块。', 'tikool-ai'),
                        ],
                        'mist_blue' => [
                            'label' => __('Mist Blue', 'tikool-ai'),
                            'prompt' => __('背景采用非常淡的 Mist Blue（雾灰蓝）冷压水彩纸效果，整体低饱和、柔和、清爽、通透。不要做成均匀纯色；在主体后方加入轻薄、自然、不规则的浅灰蓝和雾蓝水彩 wash，白色或浅色主体后方的颜料可略深一点以保持轮廓，随后向四周自然变淡并融入纸张底色。主体底部只加入很淡、松散的浅灰蓝水痕式接触阴影，不形成地面、深色阴影或大片色块。', 'tikool-ai'),
                        ],
                        'soft_sage' => [
                            'label' => __('Soft Sage', 'tikool-ai'),
                            'prompt' => __('背景采用非常淡的 Soft Sage（柔和鼠尾草绿）冷压水彩纸效果，整体低饱和、自然、安静、通透。不要做成均匀纯色；在主体后方加入轻薄、自然、不规则的浅鼠尾草绿与柔和灰绿色水彩 wash，避免鲜绿和黄绿色，色彩向四周逐渐消散并融入纸张留白。主体底部加入很淡、松散的浅鼠尾草绿水痕式接触阴影，只提供轻微落地感，不形成地面或大面积深色区域。', 'tikool-ai'),
                        ],
                        'dusty_blush' => [
                            'label' => __('Dusty Blush', 'tikool-ai'),
                            'prompt' => __('背景采用非常淡的 Dusty Blush（柔和灰粉色）冷压水彩纸效果，整体低饱和、温柔、优雅、通透。不要做成均匀纯色；在主体后方加入轻薄、自然、不规则的灰粉、裸粉和极浅暖灰水彩 wash，避免鲜粉、紫红和甜腻色调，颜色向四周自然变淡并融入纸张底色。主体底部加入很淡、松散的灰粉色水痕式接触阴影，不形成地面、深色带或明显色块。', 'tikool-ai'),
                        ],
                    ],
                ],
                'blanket_portrait_modern_studio' => [
                    'label' => __('Blanket · Modern Studio', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('根据提供的照片，先识别并完整保留照片中的全部主体，确认主体数量、类别以及各主体之间的相对位置、大小和互动关系，再创作适合 {{product_name}} 全幅印刷的 Modern Studio Portrait（现代影棚肖像）平面图稿。

输出画面必须采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例填满完整画布、延伸至四边出血区域。主体组合位于视觉中心，占画面高度约 62% 至 75%，构图端正、平衡，四周保留充足安全印刷空间；不得裁切耳朵、头顶、爪子、尾巴或原图中完整可见的重要配饰，也不要凭空补画原图中不可见的身体部位。

高度忠实还原每一个主体的身份特征，包括类别、品种、毛色或表面颜色、独特斑纹、毛发或材质纹理、头部形态、面部比例、眼距、眼睛大小与颜色、口鼻结构、耳朵形状、身体比例、姿态、朝向、表情以及实际佩戴的服装和配饰。不得美颜、幼态化、改变脸型、增加毛量、放大眼睛或标准化不同主体。

如果原图包含多个主体，必须保留全部主体以及它们原有的相对大小、位置、距离、前后关系和互动，不得增加、删除、替换、复制、合并、拆分或重新排列主体。

画面采用介于真实摄影与高端数字插画之间的现代影棚效果：结构比例真实，面部和关键纹理清晰，毛发边缘自然细腻，色彩准确，使用宽大柔光箱般的柔和主光、轻微补光和克制的自然接触阴影。避免强烈戏剧光、硬阴影、过曝高光、霓虹光、明显光圈、镜头光斑、照片棚器材或过度锐化。不要呈现水彩、油画、卡通或厚重描边效果。

{{background_prompt}}

为适应短绒法兰绒印刷，适度增强主体轮廓分离度、关键斑纹和面部局部对比，简化容易丢失的微小杂乱细节，但不得改变真实特征。背景必须平滑连续至画面四边，不得出现透明区域、白边、边框或相框。

仅移除原照片中的环境、地面杂物、牵引绳和无关元素。这是毛毯的二维印刷图稿，不是商品展示照片。不要生成毛毯、布料纹理、绒毛、褶皱、缝线、床铺、沙发、房间、墙面、人物手持效果、摄影灯或产品模型。不要添加花朵、家具、玩具或无关道具。不得生成任何文字、姓名、字母、数字、日期、logo、标志、水印或签名；姓名和文字将由网站中的独立文字图层添加。', 'tikool-ai'),
                    'variants' => [
                        'soft_white' => [
                            'label' => __('Soft White', 'tikool-ai'),
                            'prompt' => __('使用无缝的 Soft White（柔白色）现代影棚背景，以暖白、浅象牙白和极淡中性灰为主，整体明亮、干净、自然，不得纯白过曝。主体后方仅有非常轻微的中心提亮和柔和明暗过渡，保证白色或浅色毛发仍有清晰轮廓，但不得出现圆形光晕、聚光灯或明显渐变。底部加入很浅、方向一致、边缘自然消散的接触阴影，不形成地板线、墙角或摄影棚场景；背景连续延伸至四边。', 'tikool-ai'),
                        ],
                        'warm_sand' => [
                            'label' => __('Warm Sand', 'tikool-ai'),
                            'prompt' => __('使用无缝的 Warm Sand（暖沙色）现代影棚背景，以浅沙色、柔和米色和极淡暖灰褐为主，低饱和、温暖、适合现代家居。主体后方可轻微提亮，外围只作克制的柔和层次，不得偏黄、偏橙、复古泛旧或形成明显光圈。底部使用很浅的暖灰接触阴影自然承托主体，不出现地板线、墙角、台面或摄影棚设备；背景平滑延伸至四边。', 'tikool-ai'),
                        ],
                        'mist_blue_gray' => [
                            'label' => __('Mist Blue Gray', 'tikool-ai'),
                            'prompt' => __('使用无缝的 Mist Blue Gray（雾灰蓝）现代影棚背景，以明亮浅灰蓝、雾蓝和中性浅灰为主，整体清爽、安静、低饱和。避免鲜蓝、深蓝或过冷色调；主体后方允许轻微变亮或偏中性，使暖色和冷色主体都保持清晰分离，但不得出现光晕或强渐变。底部加入很浅、柔和的中性蓝灰接触阴影，不形成地面、墙角或场景；背景连续延伸至四边。', 'tikool-ai'),
                        ],
                        'muted_sage' => [
                            'label' => __('Muted Sage', 'tikool-ai'),
                            'prompt' => __('使用无缝的 Muted Sage（柔和灰鼠尾草绿）现代影棚背景，以低饱和浅灰绿和中性柔灰为主，整体自然、沉静、现代。避免鲜绿、黄绿和深橄榄色；主体后方只做轻微、宽缓的提亮以增强轮廓，不能形成明显光圈或聚光。底部加入很浅、自然、边缘消散的灰绿色接触阴影，不出现植物、地板线、墙角或摄影棚环境；背景平滑延伸至四边。', 'tikool-ai'),
                        ],
                    ],
                ],
                'blanket_portrait_playful_pattern' => [
                    'label' => __('Blanket · Playful Pattern', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('根据提供的照片，先识别照片中的全部主体，确认主体数量、类别以及各主体之间的关系，再制作适合 {{product_name}} 全幅印刷的 Playful Pattern（趣味重复图案）平面图稿。

输出画面必须采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例建立连续满版图案、延伸至四边出血区域。

准确提取每一个主体最具辨识度的头部或完整半身轮廓、面部比例、眼睛、口鼻、耳朵、毛色、独特斑纹、毛发或表面纹理、表情以及实际佩戴的服装和配饰，采用清晰、友好、精致的现代数字插画表现。不得为了可爱而改变脸型、放大眼睛、缩短口鼻、增加毛量或丢失关键花纹。

如果原图只有一个主体，以该主体制作一个完整图案单元；如果原图包含多个主体，则将全部主体按原有相对大小、位置和互动关系组成同一个完整图案单元。每一个重复单元都必须保持完全相同的主体数量、身份、外观、颜色、花纹和结构，不得在不同单元中随机改变长相、数量或配饰。

采用规则、整洁、易于阅读的半落式重复排列覆盖整个画布。图案单元大小适中，主体面部在毛毯印刷后仍清晰可辨；单元之间保持足够间距，不得过度密集、碎小、相互遮挡或被画布边缘不自然切断。允许边缘单元为实现无缝满版而自然延伸出画面，但中央主要单元必须完整。

{{background_prompt}}

背景变体指定的底色、辅助色与小型装饰图形必须在全画面保持统一。装饰仅用于填补空隙，总面积不超过画面的 12%，不得覆盖主体面部、关键斑纹或配饰。整体主色控制在四种以内，保证短绒法兰绒印刷后的轮廓和色块清楚；避免极细线条、微小复杂图标、脏乱纹理、照片拼贴或不规则随机裁切。

这是毛毯的二维印刷图稿，不是商品展示照片。不要生成毛毯、布料纹理、绒毛、褶皱、缝线、床铺、沙发、房间、人物手持效果或产品模型。不得生成任何文字、姓名、字母、数字、日期、logo、品牌角色、水印或签名；姓名和文字将由网站中的独立文字图层添加。', 'tikool-ai'),
                    'variants' => [
                        'cream_confetti' => [
                            'label' => __('Cream Confetti', 'tikool-ai'),
                            'prompt' => __('使用温暖奶油白作为连续满版底色，搭配低饱和珊瑚粉、芥末黄和浅天蓝作为辅助色。在重复主体之间均匀散布少量小圆点、迷你爱心和极简爪印，图形大小一致、间距宽松、分布有秩序，呈现温暖活泼但不幼稚的礼物感。装饰总面积严格控制在 12% 以内，不使用彩虹、文字、骨头、玩具或大型图案，不得覆盖任何主体。', 'tikool-ai'),
                        ],
                        'sky_blue_paws' => [
                            'label' => __('Sky Blue Paws', 'tikool-ai'),
                            'prompt' => __('使用明亮、低饱和的浅灰天蓝作为连续满版底色，搭配柔白、暖沙色和少量珊瑚橙作为辅助色。在重复主体之间加入稀疏、整齐、方向一致的极简爪印与小圆点，保持足够留白和清爽节奏。爪印应为简单平涂图形而非写实脚印，装饰总面积不超过 12%，不得加入云朵、文字、玩具或遮挡主体的元素。', 'tikool-ai'),
                        ],
                        'sage_geometry' => [
                            'label' => __('Sage Geometry', 'tikool-ai'),
                            'prompt' => __('使用柔和、低饱和的浅鼠尾草绿作为连续满版底色，搭配奶油白、暖米色和极少量灰蓝色。在重复主体之间有规律地放置小圆点、圆角菱形和短弧线等简洁几何图形，形成现代、整洁、安静的节奏。图形保持小巧、平涂、间距宽松，装饰总面积不超过 12%；不得加入植物叶片、复杂花纹、文字或任何会分散主体注意力的元素。', 'tikool-ai'),
                        ],
                        'blush_hearts' => [
                            'label' => __('Blush Hearts', 'tikool-ai'),
                            'prompt' => __('使用低饱和的淡粉灰作为连续满版底色，搭配暖奶油白、浅灰褐和少量柔和莓红作为辅助色。在重复主体之间均匀加入少量小型圆角爱心与细小圆点，整体温柔、轻快、适合作为纪念礼物，但避免甜腻或儿童卡通感。爱心大小一致且不成簇，装饰总面积不超过 12%，不得加入文字、花朵、丝带或覆盖主体的图形。', 'tikool-ai'),
                        ],
                    ],
                ],
                'blanket_portrait_memorial_garden' => [
                    'label' => __('Blanket · Memorial Garden', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('根据提供的照片，先识别并完整保留照片中的全部主体，确认主体数量、类别以及各主体之间的相对位置、大小和互动关系，再创作适合 {{product_name}} 全幅印刷的 Memorial Garden Portrait（温柔纪念花园肖像）平面图稿。

输出画面必须采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例填满完整画布、延伸至四边出血区域。主体组合位于视觉中心，占画面高度约 60% 至 72%，四周保留充足安全印刷空间；不得裁切耳朵、头顶、爪子、尾巴或原图中完整可见的重要配饰，也不要凭空补画原图中不可见的身体部位。

高度忠实还原每一个主体的身份特征，包括类别、品种、毛色或表面颜色、独特斑纹、头部形态、面部比例、眼距、眼睛大小与颜色、口鼻结构、耳朵形状、身体比例、姿态、朝向、表情以及实际佩戴的服装和配饰。主体面部和主要识别区域保持清晰，不得为了纪念氛围而让主体显得悲伤、衰老、患病、透明、模糊或正在消失。

如果原图包含多个主体，必须保留全部主体以及它们原有的相对大小、位置、距离、前后关系和互动，不得增加、删除、替换、复制、合并、拆分或重新排列主体。

采用柔和写实插画结合轻微水彩质感的表现方式，光线温暖、明亮、宁静，毛发和关键斑纹清晰自然，外围边缘可轻柔融入背景。整体表达陪伴、怀念、安慰和温暖，而非悲伤、宗教仪式或超自然场景。

{{background_prompt}}

花卉、叶片和散景只分布在画面下方及两侧外围，保持尺寸自然、数量克制、层次轻柔，不得遮挡主体、形成花环包围面部或占据大面积背景。为适应短绒法兰绒印刷，适度增强主体轮廓、面部和关键纹理的局部对比，避免过细、过淡的装饰。背景必须自然连续至画面四边，不得出现透明区域、白边、边框或相框。

不要自动添加翅膀、光环、彩虹桥、墓碑、十字架、蜡烛、云端、星空或其他宗教与死亡符号，除非用户在其他明确指令中要求。仅移除原照片中的环境、地面杂物、牵引绳和无关元素。

这是毛毯的二维印刷图稿，不是商品展示照片。不要生成毛毯、布料纹理、绒毛、褶皱、缝线、床铺、沙发、房间、人物手持效果或产品模型。不得生成任何文字、姓名、字母、数字、日期、logo、标志、水印或签名；姓名和文字将由网站中的独立文字图层添加。', 'tikool-ai'),
                    'variants' => [
                        'ivory_daisy_garden' => [
                            'label' => __('Ivory Daisy Garden', 'tikool-ai'),
                            'prompt' => __('背景以明亮、温暖、低饱和的象牙白和浅奶油色柔和过渡，保留淡雅水彩纸质感。仅在画面下方与两侧边缘加入少量小型白色雏菊、极浅米色花心和柔和灰绿色叶片，并点缀少量暖白散景光点；花朵疏朗、尺寸自然，不形成花束、花环或密集花海。主体后方保持大面积干净留白，浅色毛发处可用极淡暖灰增强轮廓，整体温暖、宁静、明亮。', 'tikool-ai'),
                        ],
                        'sage_meadow' => [
                            'label' => __('Sage Meadow', 'tikool-ai'),
                            'prompt' => __('背景以低饱和浅鼠尾草绿、柔和奶油白和极淡暖灰绿形成通透的水彩过渡。画面下方与两侧只加入少量纤细草叶、浅白小花和柔和散景光点，元素边缘轻柔、分布稀疏，不形成写实草地、森林或复杂场景。主体后方保持干净且略微明亮，避免鲜绿、深橄榄色和浓重阴影，整体自然、安静、有陪伴感。', 'tikool-ai'),
                        ],
                        'mist_blue_forget_me_not' => [
                            'label' => __('Mist Blue Forget-Me-Not', 'tikool-ai'),
                            'prompt' => __('背景以明亮、低饱和的雾灰蓝、柔白和极淡蓝灰形成柔和水彩过渡。仅在画面下方和两侧外围加入少量小型浅蓝勿忘我、极淡灰绿色叶片与稀疏暖白散景光点，花朵保持细小克制，不围绕面部、不形成花冠或密集花海。主体后方保留宽敞、安静的浅色空间，避免深蓝、紫蓝、夜景或忧郁暗调。', 'tikool-ai'),
                        ],
                        'blush_wildflower' => [
                            'label' => __('Blush Wildflower', 'tikool-ai'),
                            'prompt' => __('背景以低饱和淡粉灰、暖奶油白和极浅灰褐形成明亮、柔和的水彩过渡。画面下方与两侧边缘加入少量淡粉、白色和浅杏色的小型野花、细软灰绿色叶片及稀疏暖光散景，保持疏朗、不对称而平衡的自然分布。避免鲜粉、紫红、大片花丛、花环和婚礼布景感；主体后方保持干净留白，整体温柔、安慰而不过度甜美。', 'tikool-ai'),
                        ],
                    ],
                ],
                'blanket_portrait_pop_art' => [
                    'label' => __('Blanket · Pop Art', 'tikool-ai'),
                    'enabled' => true,
                    'preview_image_id' => 0,
                    'template' => __('根据提供的照片，先识别并完整保留照片中的全部主体，确认主体数量、类别以及各主体之间的相对位置、大小和互动关系，再创作适合 {{product_name}} 全幅印刷的 Modern Pop Art Portrait（现代波普艺术肖像）平面图稿。

输出画面必须采用 {{orientation}} {{aspect_ratio}} 比例（宽:高），对应 {{print_dimensions}} 的实际印刷区域，并严格按照该比例填满完整画布、延伸至四边出血区域。以主体的大幅近景或完整组合为视觉中心，占画面高度约 62% 至 76%，关键面部、耳朵、独特斑纹和重要配饰完整处于安全印刷区域内；不得为了冲击力而不自然裁切面部，也不要凭空补画原图中不可见的身体部位。

高度忠实还原每一个主体的身份特征，包括类别、品种、原始主要颜色分区、独特斑纹、头部形态、面部比例、眼距、眼睛大小与颜色、口鼻结构、耳朵形状、身体比例、姿态、朝向、表情以及实际佩戴的服装和配饰。风格化颜色可以用于阴影、高光和背景，但必须保留足够的真实毛色与标志性花纹，使主体一眼可识别；不得为了卡通效果改变脸型、放大眼睛、缩短口鼻或增加毛量。

如果原图包含多个主体，必须保留全部主体以及它们原有的相对大小、位置、距离、前后关系和互动，不得增加、删除、替换、复制、合并、拆分或重新排列主体。

采用清晰有力但不过度粗重的轮廓、平整鲜明的色块、受控的半调网点、简洁几何切分和少量丝网印刷质感。面部与关键斑纹保持清楚，色彩边界整洁，避免杂乱涂鸦、脏污套色、过密网点、照片拼贴和复杂漫画分镜。不得模仿任何具体艺术家、影视人物、品牌角色或受版权保护作品。

{{background_prompt}}

整体主色控制在四至五种协调颜色内，背景图形必须服务于主体而非抢夺注意力。为适应短绒法兰绒印刷，避免极细线条和微小网点，确保轮廓、面部和色块在织物上仍然清晰。背景必须连续延伸至画面四边，不得出现透明区域、白边、边框或相框。

仅移除原照片中的环境、地面杂物、牵引绳和无关元素。不要添加皇冠、墨镜、服装、玩具或原图中不存在的道具。这是毛毯的二维印刷图稿，不是商品展示照片。不要生成毛毯、布料纹理、绒毛、褶皱、缝线、床铺、沙发、房间、人物手持效果或产品模型。不得生成任何文字、姓名、字母、数字、日期、对话框、logo、标志、水印或签名；姓名和文字将由网站中的独立文字图层添加。', 'tikool-ai'),
                    'variants' => [
                        'coral_cobalt' => [
                            'label' => __('Coral & Cobalt', 'tikool-ai'),
                            'prompt' => __('背景使用珊瑚橙、钴蓝、暖奶油白和少量柠檬黄组成大胆但平衡的现代波普配色。采用两至三个大尺寸圆角几何色块、斜向色带和局部中等大小半调网点来衬托主体，深蓝主要用于结构与对比，珊瑚橙用于活力重点，奶油白保留呼吸空间。不得形成漫画分格、爆炸气泡、文字区域或密集满版网点；背景图形不得切过主体面部或关键斑纹。', 'tikool-ai'),
                        ],
                        'teal_sunshine' => [
                            'label' => __('Teal & Sunshine', 'tikool-ai'),
                            'prompt' => __('背景使用明亮青绿色、阳光黄、柔白和少量深青蓝组成清爽活泼的现代波普配色。以宽阔弧形色块、简洁放射线和局部受控半调网点建立节奏，黄色作为小面积亮点，青绿色作为主要背景，柔白用于分隔和留白。避免荧光色、过密放射线、漫画爆炸框和视觉眩晕；所有图形保持大而清楚，不覆盖主体面部和识别特征。', 'tikool-ai'),
                        ],
                        'berry_aqua' => [
                            'label' => __('Berry & Aqua', 'tikool-ai'),
                            'prompt' => __('背景使用莓果红、浅水蓝、柔和粉色和暖白组成明快但不甜腻的现代波普配色。采用错位圆形、圆角矩形和少量中等颗粒半调纹理形成平衡构图，莓果红用于重点对比，浅水蓝保持画面清爽，暖白保留足够负空间。避免紫黑暗调、爱心堆叠、文字框和细碎装饰；背景形状不得遮挡主体轮廓、面部或关键斑纹。', 'tikool-ai'),
                        ],
                        'orange_violet' => [
                            'label' => __('Orange & Violet', 'tikool-ai'),
                            'prompt' => __('背景使用暖橙色、柔和紫罗兰、浅桃色和奶油白组成高对比但协调的现代波普配色。用宽大的波浪色块、简洁对角色带和少量受控半调点形成动感，紫罗兰保持中等明度，避免过深发黑；奶油白在主体周围提供清晰分离。不得使用迷幻螺旋、闪电、漫画文字或过密图案，背景图形不可穿过主体面部和关键识别区域。', 'tikool-ai'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $defaults = self::defaults();
        $stored = get_option(self::OPTION_NAME, []);
        $stored = is_array($stored) ? $stored : [];
        $settings = array_replace($defaults, $stored);
        $has_stored_profiles = array_key_exists('prompt_profiles', $stored) && is_array($stored['prompt_profiles']);
        $stored_profiles = $has_stored_profiles ? $stored['prompt_profiles'] : [];
        $settings['prompt_profiles'] = $has_stored_profiles ? [] : (array) $defaults['prompt_profiles'];
        foreach ($stored_profiles as $key => $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $default_profile = is_array($defaults['prompt_profiles'][$key] ?? null)
                ? $defaults['prompt_profiles'][$key]
                : [];
            $settings['prompt_profiles'][$key] = array_replace($default_profile, $profile);
        }

        /**
         * Filter the resolved Tikool AI settings at runtime.
         *
         * Do not expose this value to browser-side JavaScript: it contains the API key.
         *
         * @param array<string, mixed> $settings Resolved settings.
         */
        return (array) apply_filters('tikool_ai_settings', $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public static function public_summary(): array
    {
        $settings = self::all();

        return [
            'configured' => trim((string) ($settings['api_key'] ?? '')) !== '',
            'endpoint' => (string) ($settings['endpoint'] ?? ''),
            'model' => (string) ($settings['model'] ?? ''),
            'size' => (string) ($settings['size'] ?? ''),
            'watermark' => ! empty($settings['watermark']),
        ];
    }

}
