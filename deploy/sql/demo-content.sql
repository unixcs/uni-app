/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: 127.0.0.1    Database: yoshop2
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `yoshop_store`
--

LOCK TABLES `yoshop_store` WRITE;
/*!40000 ALTER TABLE `yoshop_store` DISABLE KEYS */;
INSERT INTO `yoshop_store` VALUES
(10001,'观星商城','感谢您选择观星商城，希望我们的努力能为您提供好的服务',0,'','',100,0,0,1614556800,1779798310);
/*!40000 ALTER TABLE `yoshop_store` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_category`
--

LOCK TABLES `yoshop_category` WRITE;
/*!40000 ALTER TABLE `yoshop_category` DISABLE KEYS */;
INSERT INTO `yoshop_category` VALUES
(10001,'电子',0,0,1,100,10001,1779634513,1779798447),
(10002,'数码',0,0,1,100,10001,1779785230,1779785230),
(10003,'周边',0,0,1,100,10001,1779798437,1779798437);
/*!40000 ALTER TABLE `yoshop_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_goods`
--

LOCK TABLES `yoshop_goods` WRITE;
/*!40000 ALTER TABLE `yoshop_goods` DISABLE KEYS */;
INSERT INTO `yoshop_goods` VALUES
(10001,10,'123','',0,0,'',10,1.00,1.00,0.00,0.00,100,10,0,0,0,'&lt;p&gt;123&lt;/p&gt;',0,0,10001,1,1,0,'',1,0,'[]',0,'[10]',10,100,0,10001,1779634536,1779634536),
(10002,10,'达尔优鼠标','',0,0,'',10,0.01,0.01,0.00,0.00,97,10,0,0,0,'&lt;p&gt;123&lt;br/&gt;&lt;/p&gt;',0,0,10001,1,1,0,'',1,0,'[]',0,'[10]',10,100,0,10001,1779784041,1779798256),
(10003,10,'外星人蓝牙耳机','',0,0,'',10,0.01,0.01,0.00,0.00,94,10,0,0,0,'&lt;p&gt;&amp;nbsp;1&lt;/p&gt;',0,1,10001,1,1,0,'',1,0,'[]',0,'[10]',10,100,0,10001,1779784078,1779795789),
(10004,10,'海盗船青轴键盘','',0,0,'',10,1.00,1.00,0.00,0.00,100,10,0,0,0,'&lt;p&gt;1&lt;/p&gt;',0,0,10001,1,1,0,'',1,0,'[]',0,'[10]',10,100,0,10001,1779799533,1779799533);
/*!40000 ALTER TABLE `yoshop_goods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_goods_category_rel`
--

LOCK TABLES `yoshop_goods_category_rel` WRITE;
/*!40000 ALTER TABLE `yoshop_goods_category_rel` DISABLE KEYS */;
INSERT INTO `yoshop_goods_category_rel` VALUES
(10001,10001,10001,10001,1779634536),
(10002,10002,10001,10001,1779784041),
(10003,10003,10001,10001,1779784078),
(10004,10004,10003,10001,1779799533);
/*!40000 ALTER TABLE `yoshop_goods_category_rel` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_goods_image`
--

LOCK TABLES `yoshop_goods_image` WRITE;
/*!40000 ALTER TABLE `yoshop_goods_image` DISABLE KEYS */;
INSERT INTO `yoshop_goods_image` VALUES
(10001,10001,10001,10001,1779634536),
(10004,10003,10005,10001,1779795734),
(10005,10002,10008,10001,1779795745),
(10006,10004,10006,10001,1779799533);
/*!40000 ALTER TABLE `yoshop_goods_image` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_goods_sku`
--

LOCK TABLES `yoshop_goods_sku` WRITE;
/*!40000 ALTER TABLE `yoshop_goods_sku` DISABLE KEYS */;
INSERT INTO `yoshop_goods_sku` VALUES
(10001,'0',10001,0,'',1.00,0.00,100,0,'','',10001,1779634536,1779634536),
(10004,'0',10003,0,'',0.01,0.00,94,0,'','',10001,1779795734,1779795768),
(10005,'0',10002,0,'',0.01,0.00,97,0,'','',10001,1779795745,1779798256),
(10006,'0',10004,0,'',1.00,0.00,100,0,'','',10001,1779799533,1779799533);
/*!40000 ALTER TABLE `yoshop_goods_sku` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_upload_file`
--

LOCK TABLES `yoshop_upload_file` WRITE;
/*!40000 ALTER TABLE `yoshop_upload_file` DISABLE KEYS */;
INSERT INTO `yoshop_upload_file` VALUES
(10001,0,10,'local','',10,'2018071718130695f254464.jpg','10001/20260524/6f15872dc180912323026544ab3bbc43.jpg',42039,'jpg','',0,0,0,10001,1779634487,1779634487),
(10002,0,10,'local','',10,'b51b4840c24250a67210d59e345e206d.jpg','10001/20260526/335a559f7d0ae73ab2702a95588cae09.jpg',46723,'jpg','',0,0,0,10001,1779784003,1779784003),
(10003,0,10,'local','',10,'耳机.jpg','10001/20260526/a8a4b5894757c15697736e571cf13499.jpg',28716,'jpg','',0,0,0,10001,1779784003,1779784003),
(10004,0,10,'local','',10,'a8d2f0734e25e912680d0595ff1de36f~tplv-a9rns2rl98-pc_smart_face_crop-v1_321_241.png','10001/20260526/5b0bbec5b1033e7bc6e1aef19b3e6b74.png',23748,'png','',0,0,0,10001,1779784003,1779784003),
(10005,0,10,'local','',10,'键盘 (2).jpg','10001/20260526/37c66b698ca42b6c45d97d7f6adfc3be.jpg',109448,'jpg','',0,0,0,10001,1779784003,1779784003),
(10006,0,10,'local','',10,'键盘.jpg','10001/20260526/080281349005d7fdc00f04ea425d9a5c.jpg',67693,'jpg','',0,0,0,10001,1779784003,1779784003),
(10007,0,10,'local','',10,'键盘3.png','10001/20260526/457c053872cf589311b7a955d1e4cb4b.png',30569,'png','',0,0,0,10001,1779784003,1779784003),
(10008,0,10,'local','',10,'鼠标.jpg','10001/20260526/9d090e73331369e49773a92e0bc83939.jpg',34835,'jpg','',0,0,0,10001,1779784003,1779784003),
(10009,0,10,'local','',10,'06fda11d547a41a483e566ab60e636c0.jpeg~tplv-a9rns2rl98-downsize_watermark_1_5_b.png','10001/20260526/cf8686d6996f6edd532b85a20a991ee8.png',189691,'png','',0,0,1,10001,1779798775,1779800205),
(10010,0,10,'local','',10,'30ce121be3024d2a82f3be056f2d45a5.jpeg~tplv-a9rns2rl98-downsize_watermark_1_5_b.png','10001/20260526/e1f4aca5136c4dc43a6b59e2df787a6c.png',170482,'png','',0,0,1,10001,1779798777,1779800208),
(10011,0,10,'local','',10,'d460115db84840ada8a518f089489f25.jpeg~tplv-a9rns2rl98-image_raw_b.jpg','10001/20260526/efc5a2b84c8cdfc05c61641dfcea2c3b.jpg',335783,'jpg','',0,0,1,10001,1779799247,1779800201),
(10012,0,10,'local','',10,'小程序首页图片规划.jpg','10001/20260526/a43e42f0040d4eb96c88ed6e85ba5175.jpg',1235385,'jpg','',0,0,1,10001,1779799334,1779800199),
(10013,0,10,'local','',10,'01.jpg','10001/20260526/b66dddf5fd93dcde250fc4da6c4efb72.jpg',335783,'jpg','',0,0,0,10001,1779800237,1779800237),
(10014,0,10,'local','',10,'02首页图片规划.jpg','10001/20260526/dff94f9bb8a7be7a355796cde37b5ceb.jpg',1235385,'jpg','',0,0,0,10001,1779800238,1779800238),
(10015,0,10,'local','',10,'数码1.png','10001/20260526/cd93f884692f1d13e738add3dbd65646.png',189691,'png','',0,0,0,10001,1779800278,1779800278),
(10016,0,10,'local','',10,'数码2.png','10001/20260526/5c979aeea8eb4b0b610672b19f3d3178.png',170482,'png','',0,0,0,10001,1779800278,1779800278);
/*!40000 ALTER TABLE `yoshop_upload_file` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_page`
--

LOCK TABLES `yoshop_page` WRITE;
/*!40000 ALTER TABLE `yoshop_page` DISABLE KEYS */;
INSERT INTO `yoshop_page` VALUES
(10001,20,'商城首页','{\"page\":{\"name\":\"页面设置\",\"type\":\"page\",\"params\":{\"name\":\"商城首页\",\"title\":\"萤火商城2.0\",\"shareTitle\":\"分享标题\"},\"style\":{\"titleTextColor\":\"black\",\"titleBackgroundColor\":\"#ffffff\"}},\"items\":[{\"name\":\"搜索框\",\"type\":\"search\",\"params\":{\"placeholder\":\"请输入关键字进行搜索\"},\"style\":{\"textAlign\":\"left\",\"searchStyle\":\"square\"}},{\"name\":\"店铺公告\",\"type\":\"notice\",\"params\":{\"text\":\"萤火商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]\",\"link\":null,\"showIcon\":true,\"scrollable\":true},\"style\":{\"paddingTop\":0,\"background\":\"#fffbe8\",\"textColor\":\"#de8c17\"}}]}',10001,0,1614556800,1614556800),
(10002,10,'观星商城','{\"page\":{\"params\":{\"name\":\"观星商城\",\"title\":\"页面标题\",\"shareTitle\":\"观星商城\"},\"style\":{\"titleTextColor\":\"black\",\"titleBackgroundColor\":\"#ffffff\"}},\"items\":[{\"name\":\"轮播图\",\"type\":\"banner\",\"style\":{\"paddingTop\":0,\"paddingLeft\":0,\"background\":\"#ffffff\",\"btnShape\":\"round\",\"btnColor\":\"#ffffff\",\"interval\":3,\"borderRadius\":0},\"data\":[{\"imgUrl\":\"\\/uploads\\/10001\\/20260526\\/b66dddf5fd93dcde250fc4da6c4efb72.jpg\",\"imgName\":\"01.jpg\",\"link\":null},{\"imgUrl\":\"\\/uploads\\/10001\\/20260526\\/dff94f9bb8a7be7a355796cde37b5ceb.jpg\",\"imgName\":\"02首页图片规划.jpg\",\"link\":null}]},{\"name\":\"图片橱窗\",\"type\":\"window\",\"style\":{\"paddingTop\":0,\"paddingLeft\":0,\"background\":\"#ffffff\",\"layout\":2},\"data\":[{\"imgUrl\":\"\\/uploads\\/10001\\/20260526\\/cd93f884692f1d13e738add3dbd65646.png\",\"link\":{\"id\":\"995bf1c\",\"title\":\"商品列表页\",\"type\":\"PAGE\",\"param\":{\"path\":\"pages\\/goods\\/list\",\"query\":{\"categoryId\":\"10001\",\"search\":\"\"},\"url\":\"pages\\/goods\\/list?categoryId=10001\"},\"form\":[{\"key\":\"query.categoryId\",\"lable\":\"分类ID\",\"tips\":\"商品管理 -> 商品分类\",\"value\":\"10001\"},{\"key\":\"query.search\",\"lable\":\"关键词\",\"tips\":\"搜索的关键词，用于匹配商品名称\",\"value\":\"\"}]},\"imgName\":\"数码1.png\"},{\"imgUrl\":\"\\/uploads\\/10001\\/20260526\\/5c979aeea8eb4b0b610672b19f3d3178.png\",\"link\":{\"id\":\"995bf1c\",\"title\":\"商品列表页\",\"type\":\"PAGE\",\"param\":{\"path\":\"pages\\/goods\\/list\",\"query\":{\"categoryId\":\"10002\",\"search\":\"\"},\"url\":\"pages\\/goods\\/list?categoryId=10002\"},\"form\":[{\"key\":\"query.categoryId\",\"lable\":\"分类ID\",\"tips\":\"商品管理 -> 商品分类\",\"value\":\"10002\"},{\"key\":\"query.search\",\"lable\":\"关键词\",\"tips\":\"搜索的关键词，用于匹配商品名称\",\"value\":\"\"}]},\"imgName\":\"数码2.png\"}],\"dataNum\":4},{\"name\":\"在线客服\",\"type\":\"service\",\"params\":{\"type\":\"phone\",\"image\":\"http:\\/\\/localhost\\/assets\\/store\\/img\\/diy\\/service.png\",\"tel\":\"123123\"},\"style\":{\"right\":1,\"bottom\":10,\"opacity\":100}},{\"name\":\"商品组\",\"type\":\"goods\",\"params\":{\"source\":\"auto\",\"auto\":{\"category\":0,\"goodsSort\":\"all\",\"showNum\":6}},\"style\":{\"display\":\"list\",\"column\":2,\"show\":[\"goodsName\",\"goodsPrice\",\"linePrice\",\"sellingPoint\",\"goodsSales\",\"cartBtn\"],\"priceColor\":\"#ff1051\",\"sellingColor\":\"#e3771f\",\"goodsNameRows\":\"two\",\"btnCartStyle\":1,\"btnCartColor\":\"#27c29a\",\"cardType\":\"card\",\"borderRadius\":10,\"itemMargin\":10,\"background\":\"#f6f6f6\",\"paddingY\":12,\"paddingX\":12},\"defaultData\":[{\"goods_name\":\"此处显示商品名称\",\"goods_image\":\"http:\\/\\/localhost\\/assets\\/store\\/img\\/diy\\/goods\\/01.png\",\"goods_price_min\":\"99.00\",\"line_price_min\":\"139.00\",\"selling_point\":\"此款商品美观大方 不容错过\",\"goods_sales\":100},{\"goods_name\":\"此处显示商品名称\",\"goods_image\":\"http:\\/\\/localhost\\/assets\\/store\\/img\\/diy\\/goods\\/01.png\",\"goods_price_min\":\"99.00\",\"line_price_min\":\"139.00\",\"selling_point\":\"此款商品美观大方 不容错过\",\"goods_sales\":100},{\"goods_name\":\"此处显示商品名称\",\"goods_image\":\"http:\\/\\/localhost\\/assets\\/store\\/img\\/diy\\/goods\\/01.png\",\"goods_price_min\":\"99.00\",\"line_price_min\":\"139.00\",\"selling_point\":\"此款商品美观大方 不容错过\",\"goods_sales\":100},{\"goods_name\":\"此处显示商品名称\",\"goods_image\":\"http:\\/\\/localhost\\/assets\\/store\\/img\\/diy\\/goods\\/01.png\",\"goods_price_min\":\"99.00\",\"line_price_min\":\"139.00\",\"selling_point\":\"此款商品美观大方 不容错过\",\"goods_sales\":100}],\"data\":[]},{\"name\":\"关注公众号\",\"type\":\"officialAccount\",\"params\":[],\"style\":[]},{\"name\":\"备案号\",\"type\":\"ICPLicense\",\"params\":{\"text\":\"网站备案号：粤ICP备10000000号-1\",\"link\":\"https:\\/\\/beian.miit.gov.cn\\/\"},\"style\":{\"fontSize\":\"13\",\"textAlign\":\"center\",\"textColor\":\"#696969\",\"paddingTop\":6,\"paddingLeft\":0,\"background\":\"#ffffff\"}}]}',10001,0,1779635276,1779800403);
/*!40000 ALTER TABLE `yoshop_page` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_store_setting`
--

LOCK TABLES `yoshop_store_setting` WRITE;
/*!40000 ALTER TABLE `yoshop_store_setting` DISABLE KEYS */;
INSERT INTO `yoshop_store_setting` VALUES
('app_theme','店铺页面风格设置','{\"mode\":10,\"themeTemplateIdx\":0,\"data\":{\"mainBg\":\"#fa2209\",\"mainBg2\":\"#ff6335\",\"mainText\":\"#ffffff\",\"viceBg\":\"#ffb100\",\"viceBg2\":\"#ffb900\",\"viceText\":\"#ffffff\",\"gradualChange\":1}}',10001,0,1779634650);
/*!40000 ALTER TABLE `yoshop_store_setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_delivery`
--

LOCK TABLES `yoshop_delivery` WRITE;
/*!40000 ALTER TABLE `yoshop_delivery` DISABLE KEYS */;
INSERT INTO `yoshop_delivery` VALUES
(10001,'全国包邮（除偏远地区）',10,100,0,10001,1614556800,1614556800);
/*!40000 ALTER TABLE `yoshop_delivery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_delivery_rule`
--

LOCK TABLES `yoshop_delivery_rule` WRITE;
/*!40000 ALTER TABLE `yoshop_delivery_rule` DISABLE KEYS */;
INSERT INTO `yoshop_delivery_rule` VALUES
(10001,10001,'[2,20,38,61,76,84,103,123,148,165,177,194,205,218,229,240,246,259,266,273,285,299,314,332,347,357,367,371,384,393,403,418,426,438,445,458,463,477,488,496,504,511,518,526,533,541,549,554,562,570,578,589,599,606,611,619,626,632,638,648,667,684,694,703,712,722,733,744,749,760,767,778,783,801,813,821,832,839,849,858,865,873,883,890,897,904,911,925,936,949,957,963,970,980,987,992,1002,1013,1023,1032,1040,1048,1055,1060,1065,1076,1084,1093,1102,1108,1116,1121,1126,1135,1149,1156,1162,1175,1188,1200,1211,1219,1230,1240,1245,1251,1265,1268,1272,1291,1305,1316,1328,1342,1355,1366,1375,1382,1388,1401,1414,1426,1433,1438,1443,1456,1468,1477,1485,1496,1509,1519,1535,1546,1556,1562,1575,1586,1593,1600,1606,1613,1627,1637,1648,1659,1672,1686,1693,1702,1716,1726,1730,1736,1744,1753,1764,1771,1775,1789,1799,1809,1815,1828,1841,1851,1861,1866,1873,1885,1897,1910,1916,1926,1938,1949,1959,1963,1971,1977,1985,1995,2001,2010,2016,2025,2030,2037,2042,2051,2052,2053,2057,2063,2070,2083,2094,2112,2120,2125,2130,2135,2141,2149,2162,2168,2180,2187,2196,2201,2206,2207,2224,2264,2285,2292,2298,2306,2313,2323,2331,2337,2343,2355,2365,2372,2383,2390,2398,2407,2413,2417,2431,2450,2469,2480,2485,2500,2507,2516,2527,2536,2553,2567,2582,2592,2602,2608,2620,2626,2637,2646,2657,2671,2680,2684,2697,2703,2708,2795,2809,2814,2827,2842,2854,2868,2880,2893,2904,2913,2922,2923,2926,2932,2940,2945,2952,2960,2968,2977,2985,2995,3004,3014,3022,3029,3034,3039,3045,3052,3059,3067,3074,3078,3084,3090]','[{\"name\":\"北京\",\"citys\":[]},{\"name\":\"天津\",\"citys\":[]},{\"name\":\"河北省\",\"citys\":[]},{\"name\":\"山西省\",\"citys\":[]},{\"name\":\"内蒙古自治区\",\"citys\":[]},{\"name\":\"辽宁省\",\"citys\":[]},{\"name\":\"吉林省\",\"citys\":[]},{\"name\":\"黑龙江省\",\"citys\":[]},{\"name\":\"上海\",\"citys\":[]},{\"name\":\"江苏省\",\"citys\":[]},{\"name\":\"浙江省\",\"citys\":[]},{\"name\":\"安徽省\",\"citys\":[]},{\"name\":\"福建省\",\"citys\":[]},{\"name\":\"江西省\",\"citys\":[]},{\"name\":\"山东省\",\"citys\":[]},{\"name\":\"河南省\",\"citys\":[]},{\"name\":\"湖北省\",\"citys\":[]},{\"name\":\"湖南省\",\"citys\":[]},{\"name\":\"广东省\",\"citys\":[]},{\"name\":\"广西壮族自治区\",\"citys\":[]},{\"name\":\"海南省\",\"citys\":[]},{\"name\":\"重庆\",\"citys\":[]},{\"name\":\"四川省\",\"citys\":[]},{\"name\":\"贵州省\",\"citys\":[]},{\"name\":\"云南省\",\"citys\":[]},{\"name\":\"陕西省\",\"citys\":[]},{\"name\":\"甘肃省\",\"citys\":[]},{\"name\":\"青海省\",\"citys\":[]},{\"name\":\"宁夏回族自治区\",\"citys\":[]}]',1,0.00,0,0.00,10001,1614556800);
/*!40000 ALTER TABLE `yoshop_delivery_rule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_goods_service`
--

LOCK TABLES `yoshop_goods_service` WRITE;
/*!40000 ALTER TABLE `yoshop_goods_service` DISABLE KEYS */;
INSERT INTO `yoshop_goods_service` VALUES
(10001,'七天无理由退货','满足相应条件时，消费者可申请7天无理由退货',0,0,100,0,10001,1614556800,1779785255),
(10002,'全场包邮','所有商品包邮（偏远地区除外）',0,1,100,1,10001,1614556800,1779785246),
(10003,'48小时发货','下单后48小时之内发货',1,1,100,0,10001,1614556800,1614556800);
/*!40000 ALTER TABLE `yoshop_goods_service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `yoshop_goods_service_rel`
--

LOCK TABLES `yoshop_goods_service_rel` WRITE;
/*!40000 ALTER TABLE `yoshop_goods_service_rel` DISABLE KEYS */;
INSERT INTO `yoshop_goods_service_rel` VALUES
(10001,10001,10001,10001,1779634536),
(10002,10001,10003,10001,1779634536),
(10003,10002,10001,10001,1779784041),
(10004,10002,10003,10001,1779784041),
(10005,10003,10001,10001,1779784078),
(10006,10003,10003,10001,1779784078),
(10007,10004,10003,10001,1779799533);
/*!40000 ALTER TABLE `yoshop_goods_service_rel` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-28 11:04:34
