<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

// Existing #1566 reader, restricted to the ranked batch of candidate pairs.
// This program has no apply mode, publication registration or provider mutation.
const APP = '/var/www/anytoour/data/www/app.anytoour.ru';
const OPERATIONS = '/var/www/anytoour/data/channel-post-operations';
const APPLICATION_SHA = '__APPLICATION_SHA__';
const REPOSITORY_SHA = '__REPOSITORY_SHA__';
const NATIVE_RUN_ID = '__NATIVE_RUN_ID__';
const PAIRS = [
    1501 => ['name'=>'Selectum Family Resort Side', 'source'=>'339', 'target'=>'1047', 'date'=>'2025-03-14T07:11:44Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TR', 'locality'=>'Сиде', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'1d5905b0f0827e9119ac6e93d3278a6d86166ae6fe3f642e6a46e0ad3b6d43a2', 'target_photo_marker_sha256'=>'8a6db8c7d5e110c302e05058d272f7e9ce24e69ab1c07bc4c9b186a3acc38a5a'],
    2514 => ['name'=>'Amwaj Rotana Jumeirah Beach', 'source'=>'429', 'target'=>'1058', 'date'=>'2025-03-26T08:11:55Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'AE', 'locality'=>'Дубай', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'4be2e1f8c17ea5d0c44898de06c41bbfd9c799f46e1ed914b73d1b5e2f207359', 'target_photo_marker_sha256'=>'9721538fd57841ea1c435665e46f99678c732d0a7ada488b059e079a8e761c08'],
    8964 => ['name'=>'Rixos The Palm Dubai Hotel & Suites', 'source'=>'423', 'target'=>'1103', 'date'=>'2025-05-24T08:09:11Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'AE', 'locality'=>'Дубай', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'e9108bba3b78f23258aa3d01df6d642a171a59f92d77b97c4d84ff55050da9d4', 'target_photo_marker_sha256'=>'d20079a1f9045b4491da8f09a10ff109c9ab881d97fd6b3cfe3606d050bbb970'],
    1962 => ['name'=>'Baron Palms Resort', 'source'=>'475', 'target'=>'1109', 'date'=>'2025-05-29T08:16:41Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'EG', 'locality'=>'Шарм-эль-Шейх', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'9319f85290d31c8a8544fe8baedc0bd21a3b330b279a6f4d382472830de85d5b', 'target_photo_marker_sha256'=>'17d20c1df8cd8622b93d8e5cb462f7cd3d4126d752eb7ead3b191db2892c89a8'],
    36451 => ['name'=>'Edele Hotel Nha Trang', 'source'=>'482', 'target'=>'1584', 'date'=>'2026-06-16T07:39:30Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Нячанг', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'dda17e838f10abf6cf2161debc9589ce4bbe5a01458c402f38f6c324eaed30d2', 'target_photo_marker_sha256'=>'e17817561f1a057a8904d5ec775f59f0d1e7f3693094bc5d7fb05bd0772816e0'],
    278 => ['name'=>'JAZ Belvedere', 'source'=>'105', 'target'=>'1585', 'date'=>'2026-06-17T07:54:59Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'EG', 'locality'=>'Шарм эль Шейх', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'d92180e85f7c9196036e9fcef735f429b8a1df411f3f110f33e7fd2fc075985a', 'target_photo_marker_sha256'=>'f624355840ab852222db8d7692e506bda79f3e2f5ca738078cb1c20e6584a57f'],
    143372 => ['name'=>'Four Points by Sheraton Nha Trang', 'source'=>'977', 'target'=>'1586', 'date'=>'2026-06-18T07:47:41Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Нячанг', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'8a8585bb8fafe2361845695f92bc96134f51b8bd0c13472dedea9ef1a1ba6859', 'target_photo_marker_sha256'=>'297f4d4bd9b59bf919b48463d43135dba196c09fced617322ff435665bf32945'],
    7985 => ['name'=>'Kata Silver Sand', 'source'=>'372', 'target'=>'1620', 'date'=>'2026-07-15T07:29:57Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Ката, Пхукет', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'da6111adbe74ca121b0cb94690467f1e15ecc09a6584f0f84cd24edd6aa5fd72', 'target_photo_marker_sha256'=>'f83c5fe02f732574ff09c73f3c42cc884ce2a6ca87c0feeb6c4a7fdcfba80ba0'],
    68984 => ['name'=>'Le Meridien Khao Lak Resort & Spa', 'source'=>'192', 'target'=>'1651', 'date'=>'2026-08-02T08:05:10Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Као Лак', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'3a442e11bd45169e542aefaf70d3268859c5b6302f3a133226cc610c16236580', 'target_photo_marker_sha256'=>'2f9d5d700310b10021dad0b1ef864b117c60040a3d556a852457f97ea8db9580'],
    233 => ['name'=>'JAZ Casa del Mar Resort', 'source'=>'56', 'target'=>'1653', 'date'=>'2026-08-04T07:21:06Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'EG', 'locality'=>'Хургада', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'be7c29cc487ee59a5b3bebffff705170fdebd69bb8e68c739f31ba071b97d39d', 'target_photo_marker_sha256'=>'572a6189727bb6c5a31beaa4b4ae6d8f00fc5347a629646a757f71c06e015cb2'],
    66860 => ['name'=>'InterContinental Phuket Resort', 'source'=>'827', 'target'=>'1654', 'date'=>'2026-08-05T07:26:04Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Пхукет', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'3186ead1ff88c733105f40208767b801ebb185cafa2493d165cbc41c4c5c2e1e', 'target_photo_marker_sha256'=>'fc9d39a881f52b746d50661604040efa6a96cb661a752685a49f90c797b1f96d'],
    119046 => ['name'=>'Sugar Marina Resort Pop Kata Beach', 'source'=>'507', 'target'=>'1665', 'date'=>'2026-08-07T07:27:29Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Пхукет', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'2aea18b6f3dd93fe5ab53a23f79ef6dd04302ba76fafdc66c51da58bfbbfe555', 'target_photo_marker_sha256'=>'4ccb3dd5f4622dadfb80c5d8ce3209e75d858da15b6a7b0bc443ce65707450ad'],
    37396 => ['name'=>'Orka Lotus Beach', 'source'=>'358', 'target'=>'1679', 'date'=>'2026-08-12T07:12:36Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TR', 'locality'=>'Ичмелер', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'e7fdb7d4102d276ad0847110e8f81977b8cac1636994a861a62fd333b9cf8430', 'target_photo_marker_sha256'=>'5883e4d705c4cdd21b23ef85d75c2cb272f9e0f7efbc7d45576e1da6ef4c8ab7'],
    95159 => ['name'=>'Phuket Kata Resotel', 'source'=>'294', 'target'=>'1695', 'date'=>'2026-08-21T11:38:53Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Ката', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'4def9a4c15b3d6d69be3702e398e06e1b3c5ff26c508400ba0de0520d6e0e097', 'target_photo_marker_sha256'=>'0d288ee5d79eb1f3bcfc9212e26a2bf8149f842af070a68f927527cf75cf9c69'],
    57217 => ['name'=>'Wyndham Grand Phu Quoc', 'source'=>'246', 'target'=>'1696', 'date'=>'2026-08-23T10:03:05Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Фукуок', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'533479257f03b96cd6b14f3fb3dad61c5fdcb7efee4730dc5b3325794d70fe16', 'target_photo_marker_sha256'=>'94839c5e812cd76468983bb3202d5be5d24219c9784f2e7f4771a2656d161b5b'],
    2931 => ['name'=>'Jumeirah Zabeel Saray', 'source'=>'693', 'target'=>'1700', 'date'=>'2026-08-28T10:45:15Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'AE', 'locality'=>'Дубай', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'3ac8065497d95bd393f2e12749d5e08af72b491ff0313ea636808126babca605', 'target_photo_marker_sha256'=>'f5ab272dd69ff4622f97ba9c420984141228ca0c3f6ddedd7b143337667bc42b'],
    98713 => ['name'=>'Best Western Premier Marvella Nha Trang', 'source'=>'955', 'target'=>'1705', 'date'=>'2026-09-03T11:02:16Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Нячанг', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'7a451ea18f1066148e08b884be522447f3f7cbd7971b4b1ad06888a65b492218', 'target_photo_marker_sha256'=>'1c8954b96307d47978fae5889455cdbd7ef6e6c74b8588c1d3afa5dfb9d22fd2'],
    54914 => ['name'=>'The Anam Cam Ranh', 'source'=>'879', 'target'=>'1706', 'date'=>'2026-09-05T11:37:39Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Камрань', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'41bfbee618216338e87499bb823780e84ede516a849f679b7d89c6452d7581c1', 'target_photo_marker_sha256'=>'9a6ad8fddcdd463f0751138e436a8653861797678460f427baa0968c21f0077c'],
    4294 => ['name'=>'Le Méridien Phuket Mai Khao Beach Resort', 'source'=>'831', 'target'=>'1709', 'date'=>'2026-09-08T11:13:12Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Пхукет', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'8f03b732e4bb784239b4acefa3a85f56b07bbc093155be815b74c47c257d0d2a', 'target_photo_marker_sha256'=>'ceaf8bad18bdfaeccc8d762cdc9fece0cdf2a5a4dabb52a0561fb1a3be410572'],
    61591 => ['name'=>'Vinpearl Empire Nha Trang Affiliated by Meliá', 'source'=>'390', 'target'=>'1723', 'date'=>'2026-09-19T08:26:11Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Нячанг', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'80ee79942213ba727e2eb6e6ba8166dd8a57ae04b80b97cc792dbceebb4a5946', 'target_photo_marker_sha256'=>'d85aced32b96ce675cb067e10c4d41f8d283f4d1a21efdb2bc61f80eef73c267'],
    2559 => ['name'=>'Fujairah Rotana Resort & Spa', 'source'=>'509', 'target'=>'1724', 'date'=>'2026-09-21T12:23:51Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'AE', 'locality'=>'Фуджейра', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'42f1c1a4035b333d9a9eecaacb15b9a75cdd484d8edcf09936acb72bcd824b9b', 'target_photo_marker_sha256'=>'e451052e1ce95f1e45cdf2993aa223167045ff622cc2059ee9480a130a9702de'],
    61760 => ['name'=>'Saadiyat Rotana Resort & Villas', 'source'=>'373', 'target'=>'1735', 'date'=>'2026-09-27T08:20:10Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'AE', 'locality'=>'Абу-Даби', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'73cf95052015e1e3d839e529f048939107a2583b38d414d3b8f6a3a449efc4cf', 'target_photo_marker_sha256'=>'aa96aa8028694a08ea6641b1922837225274e51a5f021a248de0b472a809c881'],
    1582 => ['name'=>'Voyage Belek Golf & Spa', 'source'=>'147', 'target'=>'1739', 'date'=>'2026-09-29T11:22:12Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TR', 'locality'=>'Белек', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'0f6b7adc554883eef92bf21288a5dff6362351e54a65410434896819ecf53872', 'target_photo_marker_sha256'=>'8b2d99729aad91b8bc3ca56c3d4635999644c84b462675cfef1fea5e9fc44e95'],
    140013 => ['name'=>'Hyatt Regency Nha Trang', 'source'=>'976', 'target'=>'1741', 'date'=>'2026-10-02T11:19:29Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Нячанг', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'39086b2ec72c85b8956cc3df9dc6951d93b560dd0fe29bc0a86fd721121d71f7', 'target_photo_marker_sha256'=>'bbbd90c86f22d4c89fbaa127f547439b90f7c5048798b92c52310e789735362b'],
    262 => ['name'=>'JAZ Aquamarine', 'source'=>'50', 'target'=>'1742', 'date'=>'2026-10-03T11:11:41Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'EG', 'locality'=>'Хургада', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'97404ed9a92c3609a2404592bd2dbf71dc0aa542c95958c51a1cdc0f2744ca95', 'target_photo_marker_sha256'=>'6ebf7a76aa1cfa517337ff89c6081e09222e7f7611501bd92b4eff11bba39c62'],
    71241 => ['name'=>'Mövenpick Resort Waverly Phu Quoc', 'source'=>'884', 'target'=>'1743', 'date'=>'2026-10-05T10:38:34Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Фукуок', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'27ba54b254565339da7db2c12d2e2503c4bdca8f5aca9974735c966bd613d2b8', 'target_photo_marker_sha256'=>'20f7b8bcafffbb3507315b1d49083156d9c3a931d7b5fd341bef51f14bab0579'],
    1292 => ['name'=>'Kempinski Hotel The Dome', 'source'=>'366', 'target'=>'1744', 'date'=>'2026-10-06T11:01:49Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TR', 'locality'=>'Белек', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'e326ba5fa647ff9e1d7a82d7a5e0ed633f92e9b8fa26b825e13c23df45576bc4', 'target_photo_marker_sha256'=>'abda39b5c47a78b8b734fe097649ae3b5df374244e993696f09b77d360063edd'],
    20971 => ['name'=>'Sugar Marina Resort Nautical Kata Beach', 'source'=>'228', 'target'=>'1746', 'date'=>'2026-10-09T11:48:31Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'TH', 'locality'=>'Пхукет', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'5b19875d838ba5bac67a09d5646632b6365d3a1e085d42e8dfd5e296221b27bc', 'target_photo_marker_sha256'=>'2a47daa75393f27ba786050b2091290840aca9980890b4d4b377d88dbb996da4'],
    82751 => ['name'=>'The Anam Mui Ne', 'source'=>'950', 'target'=>'1748', 'date'=>'2026-10-12T12:04:15Z', 'rank'=>null, 'demand_platform'=>'existing_curated_wave_unmeasured_in_this_read', 'dialogues'=>null, 'entries'=>null, 'previous_feed_price'=>null, 'country'=>'VN', 'locality'=>'Фантьет', 'archived_offer'=>'Коммерческий фотопост с истёкшим предложением; подпись и тип вложения приняты из original45535459936; отзывы и видео защищены', 'archived_countries'=>[], 'different_direction'=>null, 'target_caption_sha256'=>'f529dbcdeef33b5335392f43e7b05691bedc031d58ff296e8e4946a4eeefda62', 'target_photo_marker_sha256'=>'2d7bc995459e1bb98219bfe6d45a2c363d96426a435bb83486b3f9fd69824307']
];
const PAIR_DIGEST = '1674f4d825bf5ddafa2ae1bb22826e8f8eb0c3d47fe06c90ff433e21c670e699';


function requireRead(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}
function jsonRead(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function reasonRead(Throwable $e): string
{
    return get_class($e) === RuntimeException::class
        && preg_match('/\A[a-z_]{1,100}\z/D', $e->getMessage()) === 1 ? $e->getMessage() : 'read_evidence_unavailable';
}
function publicRetryLimit(?string $raw, int $now): array
{
    if ($raw !== null && (strlen($raw)>160 || preg_match('/\A[\x20-\x7E]*\z/D',$raw)!==1)) $raw=null;
    $seconds = null;
    if ($raw !== null && preg_match('/\A[0-9]{1,8}\z/D',$raw) === 1) $seconds=(int)$raw;
    elseif ($raw !== null) {
        $when=DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231,$raw,new DateTimeZone('UTC'));
        if ($when!==false && $when->format(DateTimeInterface::RFC7231)===$raw) $seconds=max(0,$when->getTimestamp()-$now);
    }
    return ['observed_at_utc'=>gmdate(DATE_ATOM,$now), 'retry_after_raw'=>$raw,
        'retry_after_seconds'=>$seconds, 'resume_not_before_unix'=>$seconds===null?null:$now+$seconds];
}
/** Bounded host observations; never open Manager2 or return process arguments. */
function resourcesRead(): array
{
    $result = ['observed_at_utc'=>gmdate(DATE_ATOM), 'scope'=>'same_readonly_preparation_not_manager_load_test'];
    $memory = @file_get_contents('/proc/meminfo', false, null, 0, 32768);
    foreach (['MemTotal','MemAvailable','SwapTotal','SwapFree'] as $name) {
        $result['memory_kb'][$name] = is_string($memory) && preg_match('/^'. $name .':\s+([0-9]+) kB$/m', $memory, $m) ? (int)$m[1] : null;
    }
    $stat = @file_get_contents('/proc/stat', false, null, 0, 32768);
    $result['cpu_ticks'] = is_string($stat) && preg_match('/^cpu\s+([0-9 ]+)$/m',$stat,$m)
        ? array_map('intval', preg_split('/\s+/',trim($m[1]))) : null;
    $load = @file_get_contents('/proc/loadavg', false, null, 0, 256);
    $result['load_average'] = is_string($load) && preg_match('/^([0-9.]+) ([0-9.]+) ([0-9.]+) /',$load,$m)
        ? [(float)$m[1],(float)$m[2],(float)$m[3]] : null;
    $result['processes'] = ['php_fpm'=>['observed'=>0,'rss_kb'=>0], 'mysql'=>['observed'=>0,'rss_kb'=>0]];
    $paths = glob('/proc/[0-9]*/comm');
    if (is_array($paths) && count($paths) <= 8192) foreach ($paths as $path) {
        $name = @file_get_contents($path, false, null, 0, 128);
        $kind = is_string($name) && preg_match('/\Aphp-fpm[0-9.]*\n?\z/D',$name) ? 'php_fpm'
            : (is_string($name) && in_array(trim($name),['mysqld','mariadbd'],true) ? 'mysql' : null);
        if ($kind === null) continue;
        $result['processes'][$kind]['observed']++;
        $status = @file_get_contents(dirname($path).'/status', false, null, 0, 16384);
        if (is_string($status) && preg_match('/^VmRSS:\s+([0-9]+) kB$/m',$status,$m)) $result['processes'][$kind]['rss_kb'] += (int)$m[1];
    }
    $result['process_inventory_readable'] = is_array($paths) && count($paths) <= 8192;
    return $result;
}

function mysqlResourcesRead(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Innodb_row_lock_current_waits','Innodb_row_lock_time','Innodb_row_lock_waits')")->fetchAll(PDO::FETCH_ASSOC);
        $values = [];
        foreach ($rows as $row) if (preg_match('/\A[0-9]+\z/D',(string)$row['Value'])) $values[$row['Variable_name']] = (int)$row['Value'];
        return ['state'=>'observed','values'=>$values];
    } catch (Throwable $e) { return ['state'=>'unavailable']; }
}

function runtimeRead(): void
{
    requireRead(PHP_SAPI === 'cli' && realpath(APP) === APP && getcwd() === APP, 'application_root_changed');
    foreach ([
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false rev-parse HEAD' => APPLICATION_SHA,
        'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false status --porcelain --untracked-files=no' => '',
    ] as $command => $expected) {
        $lines = [];
        exec($command . ' 2>/dev/null', $lines, $status);
        requireRead($status === 0 && implode("\n", $lines) === $expected, 'installed_application_changed');
    }
}

/** Fixed public GETs only; TLS verified, no redirects or retries. */
function publicEvidence(string $channel, string $message): array
{
    global $publicAttempts, $publicRateLimit;
    $before = $publicAttempts;
    if ($publicRateLimit !== null) return ['ok'=>false, 'url'=>'https://t.me/'.$channel.'/'.$message,
        'reason'=>'public_retry_after_held', 'http_attempts'=>0, 'rate_limit'=>$publicRateLimit];
    try {
        $value = publicRead($channel,$message);
        return ['ok'=>true, 'http_attempts'=>$publicAttempts-$before] + $value;
    }
    catch (Throwable $e) {
        return ['ok'=>false, 'url'=>'https://t.me/'.$channel.'/'.$message,
            'reason'=>reasonRead($e), 'http_attempts'=>$publicAttempts-$before,
            'rate_limit'=>$publicRateLimit];
    }
}
function publicRead(string $channel, string $message): array
{
    global $publicAttempts, $publicRateLimit;
    $retryAfter = null;
    $allowed = $channel === 'anytour' ? array_column(PAIRS, 'target')
        : ($channel === 'any_toour' ? array_column(PAIRS, 'source') : []);
    requireRead(in_array($message, $allowed, true), 'public_scope_invalid');
    $url = 'https://t.me/' . $channel . '/' . $message;
    $body = '';
    $curl = curl_init($url . '?embed=1&mode=tme');
    requireRead($curl !== false, 'public_transport_unavailable');
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 18, CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HEADERFUNCTION => static function ($unused, string $header) use (&$retryAfter): int {
            if (stripos($header,'retry-after:') === 0) $retryAfter = substr(trim(substr($header,12)),0,160);
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($unused, string $part) use (&$body): int {
            if (strlen($body) + strlen($part) > 1_000_000) return 0;
            $body .= $part;
            return strlen($part);
        },
    ]);
    $publicAttempts++;
    $ok = curl_exec($curl) !== false && curl_errno($curl) === 0;
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($status === 429) {
        $publicRateLimit = publicRetryLimit($retryAfter,time());
        throw new RuntimeException('public_rate_limited');
    }
    requireRead($ok && $status === 200, 'public_post_unavailable');
    return parsePublicRead($body, $channel, $message);
}
function parsePublicRead(string $body, string $channel, string $message): array
{
    $url = 'https://t.me/' . $channel . '/' . $message;
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prior = libxml_use_internal_errors(true);
    try { $loaded = $doc->loadHTML($body, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    requireRead($loaded, 'public_post_invalid');
    $xp = new DOMXPath($doc);
    $nodes = $xp->query('//*[@data-post="' . $channel . '/' . $message . '"]');
    requireRead($nodes && $nodes->length === 1, 'public_identity_changed');
    $node = $nodes->item(0);
    $cls = static fn(string $c): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")';
    $text = $xp->query('.//*[' . $cls('tgme_widget_message_text') . ']', $node);
    $photos = $xp->query('.//*[' . $cls('tgme_widget_message_photo_wrap') . ']', $node);
    $dates = $xp->query('.//time[@datetime]', $node);
    $other = $xp->query('.//video|.//audio|.//*[' . $cls('tgme_widget_message_video_thumb')
        . ' or ' . $cls('tgme_widget_message_roundvideo') . ' or ' . $cls('tgme_widget_message_grouped') . ']', $node);
    requireRead($text && $photos && $dates && $other && $text->length === 1 && $dates->length === 1,
        'public_caption_or_date_unavailable');
    $html = '';
    foreach ($text->item(0)->childNodes as $child) $html .= $doc->saveHTML($child);
    $caption = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    requireRead($caption !== '' && mb_check_encoding($caption, 'UTF-8') && mb_strlen($caption, 'UTF-8') <= 12000,
        'public_caption_invalid');
    $single = $photos->length === 1 && $other->length === 0;
    $photo = $single ? $photos->item(0) : null;
    $marker = $photo ? trim($photo->getAttribute('class')) : '';
    $photoUrl = '';
    if ($photo && $photo->getAttribute('href') === $url
        && preg_match('/(?:^|\s)[0-9]{5,}(?:_[0-9]+)?(?:\s|$)/D', $marker) === 1
        && preg_match('~background-image:\s*url\([\'"]?(https://[^\'")]+)[\'"]?\)~', $photo->getAttribute('style'), $match) === 1) {
        $parsed = parse_url($match[1]);
        if (is_array($parsed) && ($parsed['scheme'] ?? '') === 'https'
            && preg_match('/\A(?:[a-z0-9-]+\.)*(?:cdn-telegram\.org|telegram-cdn\.org|telesco\.pe)\z/D', $parsed['host'] ?? '') === 1
            && !isset($parsed['user']) && !isset($parsed['pass']) && !isset($parsed['port'])) $photoUrl = $match[1];
    }
    $buttons = [];
    $links = $xp->query('.//a[' . $cls('tgme_widget_message_inline_button') . ']', $node);
    requireRead($links && $links->length <= 20, 'public_keyboard_unbounded');
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        $buttons[] = ['text' => trim($link->textContent),
            'hotel_url' => preg_match('~\Ahttps://t\.me/anyru_bot\?start=p_[A-Za-z0-9_-]{8,60}\z~D', $href) === 1 ? $href : null,
            'url_sha256' => hash('sha256', $href)];
    }
    $captionLinks = [];
    $anchors = $xp->query('.//a[@href]', $text->item(0));
    if ($anchors) foreach ($anchors as $anchor) {
        if (count($captionLinks) >= 40) break;
        $href = $anchor->getAttribute('href');
        $captionLinks[] = ['text'=>mb_substr(trim($anchor->textContent),0,180),
            'url_sha256'=>hash('sha256',$href),
            'public_bot_url'=>preg_match('~\\Ahttps://t\\.me/anyru_bot\\?start=(?:tg_anytour_msk|p_[A-Za-z0-9_-]{8,60})\\z~D',$href) === 1 ? $href : null];
    }
    $documentAnchors = [];
    $documentLinks = $xp->query('//a[@href]');
    if ($documentLinks) foreach ($documentLinks as $anchor) {
        if (count($documentAnchors) >= 80) break;
        $href = $anchor->getAttribute('href');
        $documentAnchors[] = ['text'=>mb_substr(trim($anchor->textContent),0,180),
            'class'=>substr($anchor->getAttribute('class'),0,256), 'url_sha256'=>hash('sha256',$href),
            'public_start_url'=>preg_match('~\Ahttps://t\.me/[A-Za-z0-9_]{5,32}\?start=[A-Za-z0-9_-]{1,128}\z~D',$href)===1 ? $href : null];
    }
    return ['url' => $url, 'caption' => $caption, 'caption_sha256' => hash('sha256', $caption),
        'html_sha256' => hash('sha256', $body), 'published_at' => $dates->item(0)->getAttribute('datetime'),
        'published_unixtime' => (new DateTimeImmutable($dates->item(0)->getAttribute('datetime')))->getTimestamp(),
        'single_photo' => $single && $photoUrl !== '', 'photo_url' => $photoUrl,
        'photo_count' => $photos->length, 'other_media_count' => $other->length, 'caption_links'=>$captionLinks,
        'document_anchors'=>$documentAnchors, 'document_anchors_truncated'=>$documentLinks && $documentLinks->length>80,
        'photo_marker_sha256' => $marker !== '' ? hash('sha256', $marker) : null, 'buttons' => $buttons];
}

/** No raw receipt, provider file id, media token or claim token leaves the host. */
function receiptRead(string $path): array
{
    if (!file_exists($path) && !is_link($path)) return ['state' => 'absent'];
    requireRead(!is_link($path) && is_file($path) && filesize($path) <= 1_000_000, 'operation_receipt_unreadable');
    $raw = file_get_contents($path);
    requireRead(is_string($raw), 'operation_receipt_unreadable');
    $lines = array_values(array_filter(explode("\n", $raw), static fn($s) => trim($s) !== ''));
    requireRead(count($lines) <= 500, 'operation_receipt_unbounded');
    $states = [];
    foreach ($lines as $line) {
        $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        $state = $record['state'] ?? '';
        $states[] = is_string($state) && preg_match('/\A[a-z0-9_:-]{1,100}\z/D', $state) === 1 ? $state : 'unclassified';
    }
    return ['state' => 'present_do_not_replay', 'sha256' => hash('sha256', $raw), 'line_count' => count($lines), 'states' => $states];
}
function publicationRead(PDO $pdo, ChannelPostRegistryService $registry, int $channel, string $message): ?array
{
    $q = $pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=? LIMIT 2');
    $q->execute([$channel, $message]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    requireRead(count($ids) <= 1, 'registry_identity_ambiguous');
    if ($ids === []) return null;
    $p = $registry->publication((int) $ids[0]);
    requireRead(is_array($p) && $p['project_key'] === 'anytour' && $p['platform'] === 'telegram'
        && $p['bot_key'] === 'anyru_bot' && $p['message_id'] === $message
        && (int) $p['channel']['id'] === $channel, 'registry_identity_changed');
    $q = $pdo->prepare('SELECT id,version_number,state,attempt_count,write_attempts,error_reason,'
        . 'claim_token IS NOT NULL AS has_claim,claimed_at IS NOT NULL AS has_claimed_at '
        . 'FROM post_versions WHERE publication_id=? ORDER BY version_number LIMIT 101');
    $q->execute([(int) $p['publication_id']]);
    $history = $q->fetchAll(PDO::FETCH_ASSOC);
    requireRead(count($history) <= 100, 'version_history_incomplete');
    $held = false;
    foreach ($history as $v) {
        if ((int) $v['has_claim'] !== 0 || (int) $v['has_claimed_at'] !== 0
            || in_array($v['state'], ['claimed', 'write_started', 'unknown'], true)) $held = true;
    }
    $s = $p['snapshot'];
    $media = [];
    foreach (($s['attachments'] ?? []) as $a) $media[] = [
        'type' => $a['type'] ?? '', 'provider' => $a['provider'] ?? '',
        'file_id_sha256' => !empty($a['telegram_file_id']) ? hash('sha256', $a['telegram_file_id']) : null,
        'file_unique_id_sha256' => !empty($a['file_unique_id']) ? hash('sha256', $a['file_unique_id']) : null,
        'reusable_telegram_photo' => ($a['type'] ?? '') === 'image' && ($a['provider'] ?? '') === 'telegram'
            && preg_match('/\A[A-Za-z0-9_-]{8,1024}\z/D', $a['telegram_file_id'] ?? '') === 1
            && preg_match('/\A[A-Za-z0-9_-]{5,160}\z/D', $a['file_unique_id'] ?? '') === 1,
    ];
    $url = ChannelPostButtonTransport::buttonUrl($p);
    $resolved = $registry->resolveToken('anytour', 'telegram', 'anyru_bot', (string) $p['start_payload']);
    $same = is_array($resolved) && (int) $resolved['publication_id'] === (int) $p['publication_id']
        && (int) $resolved['version_id'] === (int) $p['version_id'];
    $keyboardObservation = [];
    $keyboardRows = is_array($s['keyboard'] ?? null) ? $s['keyboard'] : [];
    $keyboardUnbounded = count($keyboardRows) > 20;
    $buttonsSeen = 0;
    foreach (array_slice($keyboardRows, 0, 20) as $keyRow) {
        if (!is_array($keyRow)) { $keyboardUnbounded = true; continue; }
        foreach ($keyRow as $keyButton) {
            if (++$buttonsSeen > 20) { $keyboardUnbounded = true; break 2; }
            if (!is_array($keyButton)) { $keyboardUnbounded = true; continue; }
            $keyUrl = is_string($keyButton['url'] ?? null) ? $keyButton['url'] : '';
            $keyboardObservation[] = ['type'=>substr((string)($keyButton['type'] ?? 'link'),0,30),
                'text'=>mb_substr((string)($keyButton['text'] ?? ''),0,180),
                'url_sha256'=>hash('sha256',$keyUrl),
                'public_hotel_url'=>preg_match('~\\Ahttps://t\\.me/anyru_bot\\?start=p_[A-Za-z0-9_-]{8,60}\\z~D',$keyUrl) === 1 ? $keyUrl : null];
        }
    }
    $r = $p['receipt'];
    return ['publication_id' => $p['publication_id'], 'version_id' => $p['version_id'],
        'version_number' => $p['version_number'], 'content_hash' => $p['content_hash'], 'state' => $p['state'],
        'post_url' => $s['post_url'] ?? '', 'caption_sha256' => hash('sha256', $s['text'] ?? ''),
        'hotel_id' => $s['metadata']['hotel_catalog_id'] ?? null, 'logical_id' => $s['metadata']['logical_id'] ?? null,
        'monitor_price_record_id' => $s['metadata']['monitor_price_record_id'] ?? null,
        'attachments' => $media, 'button_url' => $url,
        'one_expected_button' => $url !== '' && ChannelPostIncomingAdapter::containsOnlyButton($s, $url, $p['channel']['button_text']),
        'keyboard_observation'=>$keyboardObservation, 'keyboard_rows_count'=>count($keyboardRows), 'keyboard_unbounded'=>$keyboardUnbounded,
        'current_token_resolves' => $same, 'native_claim_held_or_unknown' => $held, 'version_history' => $history,
        'receipt' => ['sha256' => hash('sha256', jsonRead($r)), 'write_attempts' => $r['write_attempts'] ?? null,
            'request_count' => $r['request_count'] ?? null, 'content_preserved' => $r['content_preserved'] ?? null,
            'button_verified' => $r['button_verified'] ?? null],
        'content_edit_journal' => receiptRead(OPERATIONS . '/issue-1566-edit-existing-' . $p['publication_id'] . '.jsonl')];
}

$out = ['schema' => 1, 'issue' => 1566, 'demand_run_id' => null, 'demand_artifact_id' => null, 'demand_registry_sha256' => null, 'operation' => 'hotel_archive_telegram_ranked_registry', 'ok' => false,
    'repository_sha' => REPOSITORY_SHA, 'application_sha' => APPLICATION_SHA,
    'native_run_id' => NATIVE_RUN_ID, 'native_run_attempt' => 1,
    'production_writes' => 0, 'provider_writes' => 0, 'bot_starts' => 0, 'public_get_attempts' => 0];
$pdo = null;
$priceLock = null;
$publicAttempts = 0;
$publicRateLimit = null;
$stage = 'installed_release';
$readStarted = hrtime(true);
$priceLeaseAcquired = null;
try {
    $data = [];
    foreach (PAIRS as $id => $row) {
        $data[] = [$id, (int) $row['source'], (int) $row['target'], $row['date']];
    }
    requireRead(count($data) === 29 && count(array_unique(array_column($data, 1))) === 29
        && count(array_unique(array_column($data, 2))) === 29
        && !array_intersect(array_column($data, 0), [106, 109, 111, 114, 125, 132, 133, 139, 140, 150, 174, 195, 206, 231, 236, 246, 247, 260, 263, 266, 272, 287, 288, 293, 302, 358, 371, 393, 408, 415, 416, 435, 441, 454, 465, 466, 469, 483, 497, 513, 599, 632, 706, 713, 768, 800, 932, 948, 949, 954, 955, 964, 975, 996, 1013, 1032, 1035, 1055, 1105, 1260, 1344, 1451, 1960, 1972, 2470, 2493, 2588, 3405, 3415, 3452, 4091, 4181, 4270, 11791, 12107, 12111, 15693, 17595, 24909, 26844, 28460, 32342, 37668, 43676, 43689, 47025, 51238, 54605, 56092, 57028, 59114, 63191, 64895, 64914, 64918, 65877, 70048, 73343, 73974, 74503, 75100, 76588, 77914, 79071, 82106, 82606, 85417, 99713, 107938, 118475])
        && !array_intersect(array_column($data, 2), [17, 19, 23, 37, 48, 82, 192, 196, 204, 220, 224, 249, 266, 288, 316, 328, 346, 355, 372, 402, 414, 431, 455, 474, 483, 516, 540, 560, 578, 594, 603, 610, 626, 646, 702, 716, 731, 737, 755, 783, 799, 809, 819, 846, 852, 862, 867, 884, 889, 892, 894, 901, 905, 906, 908, 911, 913, 914, 915, 917, 918, 920, 923, 924, 925, 927, 928, 930, 938, 948, 949, 951, 952, 954, 955, 956, 963, 964, 969, 970, 972, 982, 983, 993, 995, 997, 999, 1001, 1003, 1012, 1016, 1018, 1020, 1034, 1037, 1042, 1043, 1046, 1049, 1051, 1052, 1055, 1057, 1059, 1063, 1069, 1074, 1083, 1087, 1090, 1092, 1095, 1098, 1101, 1110, 1128, 1131, 1148, 1150, 1207, 1227, 1292, 1312, 1380, 1469, 1484, 1570, 1588, 1652, 1678, 1754, 1760, 1779, 1780, 1782, 1784, 1787, 1788, 1789, 1790, 1792])
        && hash_equals(PAIR_DIGEST, hash('sha256', jsonRead($data))), 'input_packet_changed');
    runtimeRead();
    requireRead(realpath(OPERATIONS) === OPERATIONS, 'operations_root_changed');
    $out['resources_before'] = resourcesRead();
    $stage = 'bounded_public_photos';
    $public = [];
    foreach (PAIRS as $hotel => $pair) {
        $public[$hotel] = [publicEvidence('any_toour', $pair['source']), publicEvidence('anytour', $pair['target'])];
        $out['public_get_attempts'] = $publicAttempts;
        $out['public_rate_limit'] = $publicRateLimit;
    }
    runtimeRead();
    require_once APP . '/hotel-posts-telegram-main19.php';
    $catalogue = anytourTelegramFeedRead();
    $out['ranked_feed_sha256'] = '22407f75b33b9d4e7da0c09c6d07ec352a7da5aaa16521b6c524a4284e75c56d';
    $out['current_feed_sha256'] = hash('sha256',$catalogue);
    $feed = anytourTelegramFeedOfferUrls($catalogue);
    $catalogueDoc = new DOMDocument();
    requireRead($catalogueDoc->loadXML($catalogue,LIBXML_NONET|LIBXML_NOCDATA), 'catalogue_xml_invalid');
    $metadata = [];
    foreach ($catalogueDoc->getElementsByTagName('offer') as $offer) {
        $id = $offer->getAttribute('id');
        if (!preg_match('/^tg_([1-9][0-9]*)$/D',$id,$match) || !isset(PAIRS[(int)$match[1]])) continue;
        $metadata[$id] = ['locality'=>trim((string)$offer->getElementsByTagName('custom_label_1')->item(0)?->textContent),
            'price'=>trim((string)$offer->getElementsByTagName('price')->item(0)?->textContent)];
    }
    requireRead(count($feed) > 0, 'feed_inventory_unavailable');
    // The canonical complete public feed source is carried once for existing ROUTE preparation.
    // This is not an HTTP probe or a live route acceptance. No private database fields are exported.
    $out['route_feed_source'] = ['kind'=>'canonical_local_raw_xml', 'raw_utf8'=>$catalogue,
        'bytes'=>strlen($catalogue), 'sha256'=>hash('sha256',$catalogue), 'offer_count'=>count($feed)];
    $landings = anytourTelegramFeedDestinationPosts($feed);
    // Both possible destinations of the two table-backed rows remain reserved.
    $protectedTargets = array_fill_keys(array_merge(array_values($landings), [
        'https://t.me/anytour/1780', 'https://t.me/anytour/1760',
    ]), true);
    $lockPath = OPERATIONS . '/monitor-price-after-collection-v1.jsonl';
    requireRead(!is_link($lockPath) && is_file($lockPath), 'price_editor_lease_unknown');
    $priceLock = fopen($lockPath, 'rb');
    requireRead(is_resource($priceLock), 'price_read_lease_unavailable');
    $lockStarted = hrtime(true);
    $lockDeadline = $lockStarted + 240_000_000_000;
    $shared = false;
    do {
        $shared = flock($priceLock, LOCK_SH | LOCK_NB);
        if ($shared || hrtime(true) >= $lockDeadline) break;
        usleep(2_000_000);
    } while (true);
    $out['price_lease_wait_ms'] = (int) ((hrtime(true) - $lockStarted) / 1_000_000);
    requireRead($shared, 'price_editor_busy_or_unknown');
    runtimeRead();
    $out['price_editor_shared_read_lease'] = true;
    $priceLeaseAcquired = hrtime(true);
    $stage = 'initialize_registry';
    ob_start();
    try {
        require APP . '/config.php';
        require_once APP . '/services/ChannelPostRegistryService.php';
        require_once APP . '/services/ChannelPostButtonTransport.php';
        require_once APP . '/services/ChannelPostRuntime.php';
    } finally { ob_end_clean(); }
    requireRead(ProjectConfig::projectId() === 'anytour', 'project_changed');
    $pdo = ConversationDb::connection();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $registry = new ChannelPostRegistryService($pdo);
    $target = $registry->channel('anytour', 'telegram', 'anyru_bot', '-1001845835645');
    $source = $registry->channel('anytour', 'telegram', 'anyru_bot', '-1002262666616');
    $identity = ChannelPostRuntime::identity('telegram');
    $q = $pdo->query("SELECT pc.id,pc.platform,pc.channel_id FROM post_channels pc JOIN projects p ON p.id=pc.project_id "
        . "WHERE p.project_key='anytour' AND pc.id=4");
    $mainMax = $q->fetch(PDO::FETCH_ASSOC);
    requireRead(is_array($target) && (int) $target['id'] === 6 && $target['source_key'] === 'tg_anytour_msk'
        && ($target['post_mode'] ?? 'append_button') === 'append_button'
        && is_array($source) && (int) $source['id'] === 10
        && ($identity['project_key'] ?? '') === 'anytour' && ($identity['bot_key'] ?? '') === 'anyru_bot',
        'channel_identity_changed');
    requireRead(is_array($mainMax) && $mainMax['platform'] === 'max' && $mainMax['channel_id'] === '-68765999669267',
        'large_max_registry_identity_changed');
    $out['target_channel'] = ['registry_id' => 6, 'chat_id' => '-1001845835645', 'url' => 'https://t.me/anytour',
        'config_version' => (int) $target['config_version'], 'button_text' => $target['button_text'], 'bot_url' => $target['bot_url']];
    $stage = 'native_channel_rights';
    $targetUsernameMatches = false;
    $currentPin = null;
    $readTransport = static function (string $platform, string $method, string $path, array $query, ?array $body) use (&$targetUsernameMatches, &$currentPin): array {
        requireRead($platform === 'telegram' && $method === 'POST' && $query === []
            && in_array($path, ['getMe', 'getChatMember', 'getChat'], true)
            && ($path === 'getMe' || (string) ($body['chat_id'] ?? '') === '-1001845835645'), 'provider_read_scope_changed');
        $response = ChannelPostApiTransport::buttonRequest($platform, $method, $path, $query, $body);
        if ($path === 'getChat') {
            $chat = $response['data']['result'] ?? [];
            $targetUsernameMatches = strtolower((string) ($chat['username'] ?? '')) === 'anytour';
            $currentPin = isset($chat['pinned_message']['message_id']) ? (string) $chat['pinned_message']['message_id'] : null;
        }
        return $response;
    };
    $out['native_channel_rights'] = (new ChannelPostButtonTransport($readTransport))->verifyChannel($target);
    requireRead(($out['native_channel_rights']['receipt']['write_attempts'] ?? null) === 0, 'read_only_transport_contract_changed');
    $sourceChatRead = ChannelPostApiTransport::buttonRequest('telegram', 'POST', 'getChat', [], ['chat_id' => '-1002262666616']);
    $sourceChat = $sourceChatRead['data']['result'] ?? [];
    $sourceUsernameMatches = ($sourceChatRead['http'] ?? 0) === 200 && empty($sourceChatRead['transport_error'])
        && ($sourceChatRead['data']['ok'] ?? null) === true && ($sourceChat['type'] ?? '') === 'channel'
        && (string) ($sourceChat['id'] ?? '') === '-1002262666616'
        && strtolower((string) ($sourceChat['username'] ?? '')) === 'any_toour';
    $out['native_public_channel_binding'] = ['anytour' => $targetUsernameMatches, 'any_toour' => $sourceUsernameMatches];
    $out['native_read_request_count'] = (int) ($out['native_channel_rights']['receipt']['request_count'] ?? 0) + 1;
    $out['mysql_resources_before'] = mysqlResourcesRead($pdo);
    $pdo->beginTransaction(); // Native HTTP reads above must not hold a SQL transaction.
    $out['rows'] = [];
    foreach (PAIRS as $hotel => $pair) {
        $stage = 'hotel_' . $hotel;
        $operation = 'anytour-issue1566-historical-tg-media-' . $pair['target'] . '-v1';
        $q = $pdo->prepare('SELECT IS_FREE_LOCK(?)');
        $q->execute([$operation]);
        $free = $q->fetchColumn();
        $q->closeCursor();
        $directory = OPERATIONS . '/' . $operation;
        requireRead(!is_link($directory), 'operation_directory_symlink');
        $sourceNative = publicationRead($pdo, $registry, 10, $pair['source']);
        $targetNative = publicationRead($pdo, $registry, 6, $pair['target']);
        $albumMembers = [];
        if ($hotel === 82567) foreach (['931','932','933','934','935','936'] as $member) {
            $albumMembers[] = ['message_id'=>$member, 'native'=>publicationRead($pdo, $registry, 6, $member)];
        }
        [$sourcePublic, $targetPublic] = $public[$hotel];
        $q = $pdo->prepare("SELECT p.id AS publication_id,p.post_channel_id,p.external_message_id,v.id AS version_id,v.state,"
            . "JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url FROM post_publications p "
            . "JOIN post_versions v ON v.id=p.current_version_id AND v.publication_id=p.id "
            . "WHERE p.post_channel_id IN (4,6) AND (JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id'))=? "
            . "OR JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.logical_id')) IN (?,?)) ORDER BY p.id LIMIT 101");
        $q->execute([(string) $hotel, 'commercial-hotel-' . $hotel . '-v1', 'commercial-main-hotel-' . $hotel . '-v1']);
        $existing = $q->fetchAll(PDO::FETCH_ASSOC);
        requireRead(count($existing) <= 100, 'existing_hotel_inventory_incomplete');
        $sourceMatches = $sourcePublic['ok'] && is_array($sourceNative) && $sourceNative['state'] === 'sent'
            && $sourceNative['post_url'] === $sourcePublic['url'] && $sourceNative['caption_sha256'] === $sourcePublic['caption_sha256']
            && (string) $sourceNative['hotel_id'] === (string) $hotel && !$sourceNative['native_claim_held_or_unknown']
            && count($sourceNative['attachments']) === 1 && $sourceNative['attachments'][0]['reusable_telegram_photo']
            && $sourcePublic['single_photo'] && $sourceNative['current_token_resolves'];
        // Current checks must match exact preimages independently reviewed from original45535459936.
        $targetMatches = $pair['date'] !== null && $targetPublic['ok'] &&
            $targetPublic['caption_sha256'] === $pair['target_caption_sha256']
            && $targetPublic['photo_marker_sha256'] === $pair['target_photo_marker_sha256'] && $targetPublic['published_unixtime'] === (new DateTimeImmutable($pair['date']))->getTimestamp()
            && $targetPublic['single_photo'] && preg_match('/(?<![\pL\pN])(?:отзыв\pL*|видеокруж\pL*|видео\s+от\s+менеджер\pL*)(?![\pL\pN])/iu', $targetPublic['caption']) === 0
            && ($targetNative === null || ($targetNative['state'] === 'sent' && !$targetNative['native_claim_held_or_unknown']
                && $targetNative['post_url'] === $targetPublic['url'] && $targetNative['caption_sha256'] === $targetPublic['caption_sha256']
                && count($targetNative['attachments']) === 1 && $targetNative['attachments'][0]['type'] === 'image'
                && empty($targetNative['monitor_price_record_id'])));
        $journal = receiptRead($directory . '/receipt.jsonl');
        $existingTg = array_values(array_filter($existing, static fn($p) => (int) $p['post_channel_id'] === 6));
        $existingMax = array_values(array_filter($existing, static fn($p) => (int) $p['post_channel_id'] === 4));
        $blockers = [];
        // These fourteen targets previously carried generic search attribution.
        // An empty embedded-button parser alone cannot clear that recorded condition.
        if (in_array($pair['target'], ['1700','1705','1706','1709','1723','1724','1735','1739','1741','1742','1743','1744','1746','1748'], true)) {
            $blockers[] = 'legacy_target_keyboard_attribution_not_confirmed';
        }
        if (($sourceNative['one_expected_button'] ?? false) !== true) $blockers[] = 'source_keyboard_requires_exact_review';
        if ($hotel === 82567) $blockers[] = 'album_membership_caption_and_keyboard_not_admitted';
        $targetUrl = 'https://t.me/anytour/' . $pair['target'];
        if (($feed['tg_' . $hotel] ?? null) !== 'https://t.me/any_toour/' . $pair['source']) $blockers[] = 'source_offer_changed';
        if (($metadata['tg_'.$hotel]['locality'] ?? null) !== $pair['locality']) $blockers[] = 'selected_catalogue_locality_changed';
        if (isset($protectedTargets[$targetUrl])) $blockers[] = 'target_used_by_live_telegram_offer';
        if ($pair['target'] === $currentPin) $blockers[] = 'target_is_current_channel_pin';
        if (($targetPublic['ok'] ?? false) !== true) $blockers[] = 'target_keyboard_not_observed';
        elseif (($targetPublic['buttons'] ?? null) !== []) $blockers[] = 'target_buttons_changed';
        if (!$sourceMatches) $blockers[] = 'source_snapshot_or_photo_not_confirmed';
        if (!$targetMatches) $blockers[] = 'historical_target_not_confirmed';
        if ($free === false || $free === null || (int) $free !== 1) $blockers[] = 'target_editor_busy_or_unknown';
        if (file_exists($directory)) $blockers[] = 'existing_media_operation_do_not_replay';
        if ($targetNative !== null && $targetNative['content_edit_journal']['state'] !== 'absent') $blockers[] = 'existing_content_operation_requires_readback';
        if ($existingTg !== []) $blockers[] = 'hotel_already_registered_in_large_telegram';
        if (($out['native_channel_rights']['state'] ?? '') !== 'ready') $blockers[] = 'channel_edit_rights_not_confirmed';
        if (!$targetUsernameMatches || !$sourceUsernameMatches) $blockers[] = 'native_public_channel_binding_not_confirmed';
        $out['rows'][] = ['target_current_pin_matches' => $currentPin === $pair['target'], 'hotel_id' => $hotel, 'name' => $pair['name'], 'source_message_id' => $pair['source'],
            'target_message_id' => $pair['target'], 'source_native' => $sourceNative, 'target_native' => $targetNative,
            'target_album_native_members' => $albumMembers,
            'source_public' => $sourcePublic, 'target_public' => $targetPublic,
            'target_media_lock_free' => $free !== false && $free !== null && (int) $free === 1,
            'prior_media_operation_directory_exists' => file_exists($directory), 'prior_media_operation' => $journal,
            'existing_large_channel_hotel_registry_matches' => $existing, 'large_max_hotel_already_registered' => $existingMax !== [],
            'demand_rank' => $pair['rank'], 'demand_platform' => $pair['demand_platform'],
            'distinct_dialogues' => $pair['dialogues'], 'entries' => $pair['entries'],
            'previous_catalogue_price_rub' => $pair['previous_feed_price'], 'source_country' => $pair['country'],
            'source_locality' => $pair['locality'], 'current_catalogue_price_raw' => $metadata['tg_'.$hotel]['price'] ?? null, 'previous_target_offer' => $pair['archived_offer'], 'archived_countries' => $pair['archived_countries'], 'different_direction' => $pair['different_direction'],
            'source_matches' => $sourceMatches,
            'historical_target_matches' => $targetMatches,
            'preview_blockers' => $blockers, 'preview_ready' => $blockers === []];
    }
    $out['mysql_resources_after'] = mysqlResourcesRead($pdo);
    $pdo->rollBack();
    runtimeRead();
    $out['read_at_utc'] = gmdate(DATE_ATOM);
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['failure_stage'] = $stage;
    // Only our fixed machine reason is exposed; PDO/provider diagnostics may contain secrets.
    $out['failure_reason'] = reasonRead($e);
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (is_resource($priceLock)) {
        $out['price_lease_held_ms'] = $priceLeaseAcquired === null ? 0 : (int) ((hrtime(true) - $priceLeaseAcquired) / 1_000_000);
        flock($priceLock, LOCK_UN); fclose($priceLock);
    }
}
$out['resources_after'] = resourcesRead();
$out['read_elapsed_ms'] = (int) ((hrtime(true) - $readStarted) / 1_000_000);
if ($out['ok']) $out['checkpoint_sha256'] = hash('sha256', jsonRead($out));
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($out['ok'] ? 0 : 2);







