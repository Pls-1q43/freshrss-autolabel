# FreshRSS AutoLabel

[English](../README.md) | [中文](./README.zh-CN.md) | [Français](./README.fr.md)

FreshRSS AutoLabel 用于通过 LLM 分类或 Embedding 零样本匹配，自动为 FreshRSS 文章打上标签。

作者：[Pls](https://1q43.blog)  
项目主页、使用说明与更新：[github.com/Pls-1q43/freshrss-autolabel](https://github.com/Pls-1q43/freshrss-autolabel)

## 功能概览

AutoLabel 采用混合权限模型：

- 管理员发布可用模型档案
- 用户基于这些档案创建自己的 AutoLabel 规则

支持内容包括：

- 管理员管理模型档案
- 用户管理 AutoLabel 规则
- LLM 模式与 Embedding 模式
- 每条规则可绑定多个 FreshRSS 已有标签
- 新文章与回填统一走异步队列
- 若 PHP 提供 `curl_multi`，可启用并发窗口

## 支持的 Provider

| Provider | LLM | Embedding |
| --- | --- | --- |
| OpenAI | 支持 | 支持 |
| Anthropic | 支持 | 不支持 |
| Gemini | 支持 | 支持 |
| Ollama | 支持 | 支持 |

说明：

- Anthropic 仅支持 LLM 模式
- Embedding 目标标签必须预先在 FreshRSS 中创建
- 队列并发依赖 PHP `curl_multi`

## 推荐的 Ollama Embedding 配置

特别推荐通过 Ollama 使用下面这组参数进行零样本分类：

- 模型：`qwen3-embedding:0.6b`
- 最大内容长度：`1500`
- `Embedding num_ctx`：`2000`
- Instruct / instruction：使用英文撰写
- 相似度阈值：`0.65`

这组配置很适合作为本地轻量 Embedding 分类的默认起点。

## 安装

### 方式一：下载 Release 包

1. 前往 [GitHub Releases](https://github.com/Pls-1q43/freshrss-autolabel/releases) 下载最新版本。
2. 解压到 FreshRSS 的 `extensions/` 目录。
3. 确保目录名为：

```text
xExtension-AutoLabel
```

4. 在 FreshRSS 扩展页面启用 `AutoLabel`。

### 方式二：直接克隆仓库

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/Pls-1q43/freshrss-autolabel.git xExtension-AutoLabel
```

然后在 FreshRSS 中启用扩展。

## 配置模型

### 管理员负责

- Provider
- 模型名
- 模式（LLM / Embedding）
- Base URL
- API Key
- 超时
- 最大内容长度
- 并发窗口大小（`batch_size`）
- Embedding 维度
- Embedding `num_ctx`
- 默认 instruction

其中 `batch_size` 表示**并发窗口大小**，不是串行批次数。

### 用户负责

- 规则名称
- 目标标签
- 选用的模型档案
- 规则模式
- Prompt
- Embedding 锚定文本
- 相似度阈值
- instruction

## 队列处理

AutoLabel 的异步队列主要处理：

- 新入库文章
- 回填任务

队列可以通过以下方式消费：

- FreshRSS 的 `FreshrssUserMaintenance`
- 控制台中的手动处理
- 管理员单独调度的 queue worker

## 权限模型

- 未登录用户不能访问 AutoLabel 页面
- 管理员可见模型档案管理与 queue worker 地址
- 普通登录用户可管理自己的规则、回填、队列与诊断
- 普通登录用户不能访问管理员的模型档案管理

## 故障排查

- 队列持续积压：
  - 先确认 FreshRSS maintenance 是否真的在运行
- 看不到并发：
  - 先确认 PHP 是否启用了 `curl_multi`
- Ollama Embedding 超时：
  - 联合检查 `content_max_chars`、`timeout_seconds`、`embedding_num_ctx` 与 Ollama 日志
- 标签未打上：
  - 确认目标标签已经在 FreshRSS 中存在

## 许可证

本项目使用 **GNU GPL 3.0** 许可证。  
详见 [LICENSE](../LICENSE)。
