# 卡密批量上传开放API文档

## 一、接口概览

| 接口 | 方法 | 路径 | 说明 |
|------|------|------|------|
| 批量上传卡密 | POST | `/opencard/upload` | 上传卡密到指定商品 |
| 批量清除卡密 | POST | `/opencard/remove` | 按卡密内容清除未出售卡密，可限定商品 |
| 获取商品列表 | POST | `/opencard/commodities` | 获取用户可操作的商品列表 |

---

## 二、认证方式

采用 **API Key + 签名** 的认证方式：

### 认证信息获取

商户需要在用户中心查看：
- **Api-Id**：用户的 `app_id`（商户ID）
- **Api-Key**：用户的 `app_key`（对接密钥）

位置：用户中心 → 控制台 → 商户密钥

---

## 三、请求头

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `Api-Id` | string | 是 | 商户ID (app_id) |
| `Api-Signature` | string | 是 | 请求签名 |

---

## 四、签名算法

签名使用 **MD5** 算法生成，步骤如下：

1. **准备数据**：获取所有 POST 参数（不包含 sign 字段）
2. **排序**：按参数名 ASCII 升序排序
3. **拼接**：生成 `key1=value1&key2=value2...&key=你的Api-Key`
4. **签名**：对拼接后的字符串进行 MD5 加密

**Node.js 签名示例：**
```javascript
const crypto = require('crypto');

function generateSignature(data, appKey) {
    delete data.sign;
    const sortedKeys = Object.keys(data).sort();
    const filteredData = {};
    sortedKeys.forEach(key => {
        if (data[key] !== '') {
            filteredData[key] = data[key];
        }
    });
    const queryString = new URLSearchParams(filteredData).toString();
    const signString = decodeURIComponent(queryString) + "&key=" + appKey;
    return crypto.createHash('md5').update(signString).digest('hex');
}
```

---

## 五、接口详细说明

### 1. 批量上传卡密

**接口地址**：`POST /opencard/upload`

**请求参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `commodity_id` | int | 是 | 商品ID |
| `secret` | string | 是 | 卡密内容，一行一个 |
| `card_type` | int | 否 | 卡密类型：0=普通卡密，1=账号/预告 |
| `unique` | int | 否 | 是否去重：0=否，1=是 |
| `note` | string | 否 | 备注信息 |
| `race` | string | 否 | 分类名称 |
| `race_get_mode` | int | 否 | 分类获取方式：0=选择，1=输入 |
| `race_input` | string | 否 | 自定义分类名称 |
| `sku` | array | 否 | SKU信息 |

**卡密格式说明**：

- **普通卡密（card_type=0）**：每行一个卡密
  ```
  ABCDEF-GHIJ-KLMN
  VIP-2025-0821-XYZ
  ```

- **账号/预告（card_type=1）**：使用 `║` 分隔
  ```
  账号1║密码1║加价金额║成本价
  user001║pass123║5.00║20.00
  ```

**成功响应**：
```json
{
    "code": 200,
    "msg": "共计导入:10张卡密，成功:9张，失败：1张",
    "data": {
        "total": 10,
        "success": 9,
        "error": 1
    }
}
```

**失败响应**：
```json
{
    "code": 0,
    "msg": "签名验证失败"
}
```

### 2. 批量清除卡密

**接口地址**：`POST /opencard/remove`

**请求参数**：

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `commodity_id` | int | 否 | 商品ID；不传则在当前商户全部未出售卡密中按卡密内容清除 |
| `secret` | string | 是 | 需要清除的卡密，一行一个 |
| `cards` | string/array | 否 | 也可传 JSON 数组；传入时优先使用 |

仅会删除 `status=0` 的未出售卡密；传入 `commodity_id` 时限定该商品，不传时在当前商户全部未出售卡密中按卡密内容清除。已售出并关联订单的卡密不会被删除。

**成功响应**：
```json
{
    "code": 200,
    "msg": "当前商户共计请求清除:2张卡密，成功:2张，未找到:0张",
    "data": {
        "commodity_id": null,
        "total": 2,
        "removed": 2,
        "missing": 0
    }
}
```

### 3. 获取商品列表

**接口地址**：`POST /opencard/commodities`

**请求参数**：无额外参数（只需签名）

**成功响应**：
```json
{
    "code": 200,
    "msg": "success",
    "data": [
        {"id": 1, "name": "商品A", "code": "P001"},
        {"id": 2, "name": "商品B", "code": "P002"}
    ]
}
```

---

## 六、调用示例

### Node.js 示例

```javascript
const axios = require('axios');
const crypto = require('crypto');

// 配置
const config = {
    appId: 'YOUR_APP_ID',
    appKey: 'YOUR_APP_KEY',
    baseUrl: 'https://your-domain.com'
};

// 签名生成函数
function generateSignature(data, appKey) {
    delete data.sign;
    const sortedKeys = Object.keys(data).sort();
    const filteredData = {};
    sortedKeys.forEach(key => {
        if (data[key] !== '') {
            filteredData[key] = data[key];
        }
    });
    const queryString = new URLSearchParams(filteredData).toString();
    const signString = decodeURIComponent(queryString) + "&key=" + appKey;
    return crypto.createHash('md5').update(signString).digest('hex');
}

// 发送请求函数
async function sendRequest(endpoint, data) {
    const signature = generateSignature({ ...data }, config.appKey);
    
    try {
        const response = await axios.post(`${config.baseUrl}${endpoint}`, data, {
            headers: {
                'Api-Id': config.appId,
                'Api-Signature': signature,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            transformRequest: [function (data) {
                return new URLSearchParams(data).toString();
            }]
        });
        return response.data;
    } catch (error) {
        if (error.response) {
            return error.response.data;
        }
        throw error;
    }
}

// 示例1：获取商品列表
async function getCommodities() {
    console.log('=== 获取商品列表 ===');
    const result = await sendRequest('/opencard/commodities', {});
    console.log(JSON.stringify(result, null, 2));
    return result;
}

// 示例2：批量上传卡密
async function uploadCards() {
    console.log('\n=== 批量上传卡密 ===');
    
    const cardData = {
        commodity_id: 1,
        card_type: 0,
        unique: 1,
        note: 'API批量导入',
        secret: 'ABCDEF-GHIJ-KLMN\nVIP-2025-0821-XYZ\nVIP-2025-0822-XYZ'
    };
    
    const result = await sendRequest('/opencard/upload', cardData);
    console.log(JSON.stringify(result, null, 2));
    return result;
}

// 执行示例
(async () => {
    try {
        await getCommodities();
        await uploadCards();
    } catch (error) {
        console.error('请求失败:', error.message);
    }
})();
```

### 使用 Node.js 内置模块（无依赖）示例

```javascript
const https = require('https');
const http = require('http');
const crypto = require('crypto');
const querystring = require('querystring');

// 配置
const config = {
    appId: 'YOUR_APP_ID',
    appKey: 'YOUR_APP_KEY',
    baseUrl: 'your-domain.com',
    useHttps: true
};

// 签名生成函数
function generateSignature(data, appKey) {
    delete data.sign;
    const sortedKeys = Object.keys(data).sort();
    const filteredData = {};
    sortedKeys.forEach(key => {
        if (data[key] !== '') {
            filteredData[key] = data[key];
        }
    });
    const queryString = querystring.stringify(filteredData);
    const signString = decodeURIComponent(queryString) + "&key=" + appKey;
    return crypto.createHash('md5').update(signString).digest('hex');
}

// 发送请求函数
function sendRequest(endpoint, data) {
    return new Promise((resolve, reject) => {
        const signature = generateSignature({ ...data }, config.appKey);
        const postData = querystring.stringify(data);
        
        const options = {
            hostname: config.baseUrl,
            port: config.useHttps ? 443 : 80,
            path: endpoint,
            method: 'POST',
            headers: {
                'Api-Id': config.appId,
                'Api-Signature': signature,
                'Content-Type': 'application/x-www-form-urlencoded',
                'Content-Length': Buffer.byteLength(postData)
            }
        };
        
        const protocol = config.useHttps ? https : http;
        
        const req = protocol.request(options, (res) => {
            let body = '';
            res.on('data', (chunk) => {
                body += chunk;
            });
            res.on('end', () => {
                try {
                    resolve(JSON.parse(body));
                } catch (e) {
                    reject(new Error('解析响应失败'));
                }
            });
        });
        
        req.on('error', (error) => {
            reject(error);
        });
        
        req.write(postData);
        req.end();
    });
}

// 示例：批量上传卡密
async function main() {
    try {
        // 获取商品列表
        console.log('获取商品列表...');
        const commodities = await sendRequest('/opencard/commodities', {});
        console.log('商品列表:', commodities);
        
        if (commodities.code === 200 && commodities.data.length > 0) {
            const commodityId = commodities.data[0].id;
            
            // 上传卡密
            console.log('\n上传卡密到商品 ID:', commodityId);
            const result = await sendRequest('/opencard/upload', {
                commodity_id: commodityId,
                card_type: 0,
                unique: 1,
                secret: 'CARD001-XXXX-XXXX\nCARD002-XXXX-XXXX\nCARD003-XXXX-XXXX'
            });
            console.log('上传结果:', result);
        }
    } catch (error) {
        console.error('错误:', error.message);
    }
}

main();
```

---

## 七、注意事项

1. **签名安全性**：签名时参数值为空的字段会被忽略，请确保参数值正确
2. **字符编码**：请求编码必须为 UTF-8
3. **卡密内容**：`secret` 参数支持 `\n` 或 `\r\n` 换行符
4. **并发限制**：建议单次上传不超过 1000 条，如需大量上传请分批处理
5. **IP 白名单**：如有需要可联系管理员配置 IP 白名单
6. **Content-Type**：请使用 `application/x-www-form-urlencoded` 格式发送数据

---

## 八、错误码说明

| 错误码 | 说明 |
|--------|------|
| 0 | 请求失败（签名失败、参数错误等） |
| 200 | 请求成功 |

---

## 九、文件说明

- **API 控制器**：`app/Controller/OpenApi/Card.php`
- **本文档**：根目录下 `第三方卡密api文档.md`

---

**更新时间**：2026-05-07
